<?php

namespace App\Modules\Timeline\Application;

use App\Modules\Timeline\Domain\TimelinePhiViolationException;
use App\Modules\Timeline\Domain\TimelineProjectionException;
use App\Modules\Timeline\Domain\TimelineSubject;
use App\Modules\Timeline\Domain\TimelineType;
use App\Modules\Timeline\Models\TimelineEntry;
use App\Support\Database\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes the timeline projection (CRM-011 / DM-007).
 *
 * The write is idempotent on the `(ref_type, ref_id, type)` unique key, so an
 * observer retry, a replay or a rebuild run can never duplicate an entry.
 *
 * Metadata is validated against the normative redaction rule *before* the
 * write. A PHI-shaped key (`value`, `note`, `name`, `substance`, `title`,
 * `purpose`, `location`, ...) throws instead of being silently dropped: a
 * projection bug must be loud, and no health content may enter the read model.
 *
 * Observers call {@see forSubject()} with the identity captured from the
 * source row, because the actual write happens after commit when the request's
 * transaction-local RLS context has already disappeared (ADR-013 / N-01).
 */
class TimelineProjector
{
    /**
     * Exact keys forbidden by the task's redaction rule (DM-007).
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'value',
        'secondary_value',
        'note',
        'notes',
        'description',
        'name',
        'substance',
        'reaction',
        'title',
        'purpose',
        'location',
        'instructions',
    ];

    /**
     * Suffix guard for prefixed variants such as `medication_name` or
     * `lab_value`. The rule is "ids, enums and timestamps only", so a key that
     * ends in a PHI noun is rejected even when it is not in the exact list.
     *
     * @var list<string>
     */
    private const FORBIDDEN_SUFFIXES = [
        '_value',
        '_note',
        '_notes',
        '_description',
        '_name',
        '_substance',
        '_reaction',
        '_title',
        '_purpose',
        '_location',
        '_instructions',
    ];

    private ?TimelineSubject $subject = null;

    /**
     * Return a projector bound to one trusted subject. The original instance
     * is left untouched, which keeps a container-resolved projector safe for
     * concurrent/long-lived workers.
     */
    public function forSubject(string $tenantId, string $workspaceId, string $profileId): static
    {
        $bound = clone $this;
        $bound->subject = new TimelineSubject($tenantId, $workspaceId, $profileId);

        return $bound;
    }

    /**
     * Project one source event.
     *
     * `$occurredAt` is the source event clock (recorded_at, occurred_at,
     * created_at), never the projection clock.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $type,
        string $refType,
        string $refId,
        string $occurredAt,
        string $summaryKey,
        array $metadata = [],
    ): void {
        if (! TimelineType::isKnown($type)) {
            throw new TimelineProjectionException("Unknown timeline type [{$type}].");
        }

        if ($refType === '' || $refId === '' || $summaryKey === '') {
            throw new TimelineProjectionException('Timeline reference and summary key are required.');
        }

        $this->assertSafeMetadata($metadata);

        $subject = $this->subject;

        if ($subject === null) {
            throw new TimelineProjectionException(
                'Timeline projection requires a trusted tenant/workspace/profile subject.'
            );
        }

        $occurred = Carbon::parse($occurredAt);

        try {
            DB::transaction(function () use ($subject, $type, $refType, $refId, $occurred, $summaryKey, $metadata): void {
                TenantContext::run($subject->tenantId, $subject->workspaceId, function () use (
                    $subject,
                    $type,
                    $refType,
                    $refId,
                    $occurred,
                    $summaryKey,
                    $metadata,
                ): void {
                    $this->upsert($subject, $type, $refType, $refId, $occurred, $summaryKey, $metadata);
                });
            });
        } catch (QueryException $exception) {
            // A concurrent projection of the same source is not an error: the
            // unique key already won the insert. Re-run as an update once.
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            TenantContext::run($subject->tenantId, $subject->workspaceId, function () use (
                $subject,
                $type,
                $refType,
                $refId,
                $occurred,
                $summaryKey,
                $metadata,
            ): void {
                DB::transaction(function () use (
                    $subject,
                    $type,
                    $refType,
                    $refId,
                    $occurred,
                    $summaryKey,
                    $metadata,
                ): void {
                    $this->upsert($subject, $type, $refType, $refId, $occurred, $summaryKey, $metadata);
                });
            });
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function upsert(
        TimelineSubject $subject,
        string $type,
        string $refType,
        string $refId,
        Carbon $occurred,
        string $summaryKey,
        array $metadata,
    ): void {
        $entry = TimelineEntry::query()
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->where('type', $type)
            ->first();

        if ($entry === null) {
            TimelineEntry::query()->create([
                'tenant_id' => $subject->tenantId,
                'workspace_id' => $subject->workspaceId,
                'profile_id' => $subject->profileId,
                'occurred_at' => $occurred,
                'type' => $type,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'summary_key' => $summaryKey,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            return;
        }

        // A re-projection may refresh the source clock, summary key or safe
        // metadata, but the row identity never changes.
        $entry->fill([
            'occurred_at' => $occurred,
            'summary_key' => $summaryKey,
            'metadata' => $metadata,
        ])->save();
    }

    /**
     * Recursively reject PHI-shaped keys. Arrays inside metadata are allowed
     * only as containers (e.g. a list of ids); every key at every level is
     * checked so a nested `{"plan":{"name":"..."}}` cannot slip through.
     *
     * @param  array<string|int, mixed>  $metadata
     */
    private function assertSafeMetadata(array $metadata, string $path = ''): void
    {
        foreach ($metadata as $key => $value) {
            if (is_int($key)) {
                $this->assertSafeMetadata(is_array($value) ? $value : [], $path);

                continue;
            }

            $normalised = strtolower((string) $key);
            $violates = in_array($normalised, self::FORBIDDEN_KEYS, true);

            foreach (self::FORBIDDEN_SUFFIXES as $suffix) {
                if (str_ends_with($normalised, $suffix)) {
                    $violates = true;

                    break;
                }
            }

            if ($violates) {
                $location = $path === '' ? $normalised : $path.'.'.$normalised;

                throw new TimelinePhiViolationException(
                    "Timeline metadata key [{$location}] is PHI-shaped and cannot be projected."
                );
            }

            if (is_array($value)) {
                $this->assertSafeMetadata($value, $path === '' ? $normalised : $path.'.'.$normalised);
            }
        }
    }
}
