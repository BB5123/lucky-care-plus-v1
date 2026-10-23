<?php

namespace App\Modules\Timeline\Application;

use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\HealthRecord\Models\MedicationRecord;
use App\Modules\Timeline\Domain\TimelineProjection;
use App\Modules\Timeline\Domain\TimelineType;
use App\Modules\Timeline\Models\TimelineEntry;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `timeline_entries` from the owning modules' tables (CRM-011).
 *
 * The rebuild is the proof that the timeline is a projection and not a second
 * source of truth: it recomputes every desired entry with the same
 * {@see TimelineSource} mapping the observers use, upserts the missing or
 * changed rows, and deletes rows whose source no longer exists.
 *
 * It is idempotent: identical rows are not rewritten, so a second run reports
 * `created=0 updated=0 removed=0`. `--dry-run` computes exactly the same
 * counters without writing anything.
 */
final class TimelineRebuilder
{
    public function __construct(private readonly TimelineProjector $projector) {}

    /**
     * @return array<string, array{created: int, updated: int, removed: int}>
     */
    public function rebuild(?string $profileId = null, bool $dryRun = false): array
    {
        $stats = $this->emptyStats();

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            DB::transaction(function () use ($tenantId, $profileId, $dryRun, &$stats): void {
                TenantContext::run($tenantId, '', function () use ($tenantId, $profileId, $dryRun, &$stats): void {
                    $workspaceIds = Workspace::query()->orderBy('id')->pluck('id');

                    foreach ($workspaceIds as $workspaceId) {
                        TenantContext::run(
                            $tenantId,
                            (string) $workspaceId,
                            function () use ($tenantId, $workspaceId, $profileId, $dryRun, &$stats): void {
                                $this->rebuildWorkspace(
                                    $tenantId,
                                    (string) $workspaceId,
                                    $profileId,
                                    $dryRun,
                                    $stats,
                                );
                            },
                        );
                    }
                });
            });
        }

        return $stats;
    }

    /**
     * @param  array<string, array{created: int, updated: int, removed: int}>  $stats
     */
    private function rebuildWorkspace(
        string $tenantId,
        string $workspaceId,
        ?string $profileId,
        bool $dryRun,
        array &$stats,
    ): void {
        $profileIds = HealthProfile::query()
            ->when(
                $profileId !== null && $profileId !== '',
                fn (Builder $query) => $query->whereKey($profileId),
            )
            ->orderBy('id')
            ->pluck('id');

        foreach ($profileIds as $profile) {
            $this->rebuildProfile(
                $tenantId,
                $workspaceId,
                (string) $profile,
                $dryRun,
                $stats,
            );
        }
    }

    /**
     * @param  array<string, array{created: int, updated: int, removed: int}>  $stats
     */
    private function rebuildProfile(
        string $tenantId,
        string $workspaceId,
        string $profileId,
        bool $dryRun,
        array &$stats,
    ): void {
        $desired = $this->desiredEntries($profileId);

        /** @var array<string, TimelineEntry> $existing */
        $existing = TimelineEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('profile_id', $profileId)
            ->get()
            ->keyBy(fn (TimelineEntry $entry): string => $this->key(
                $entry->type,
                $entry->ref_type,
                $entry->ref_id,
            ))
            ->all();

        foreach ($existing as $key => $entry) {
            $projection = $desired[$key] ?? null;

            if ($projection === null) {
                if (! $dryRun) {
                    $entry->delete();
                }

                $this->bump($stats, (string) $entry->type, 'removed');

                continue;
            }

            if (! $this->differs($entry, $projection)) {
                continue;
            }

            if (! $dryRun) {
                $this->write($tenantId, $workspaceId, $profileId, $projection);
            }

            $this->bump($stats, $projection->type, 'updated');
        }

        foreach ($desired as $key => $projection) {
            if (isset($existing[$key])) {
                continue;
            }

            if (! $dryRun) {
                $this->write($tenantId, $workspaceId, $profileId, $projection);
            }

            $this->bump($stats, $projection->type, 'created');
        }
    }

    /**
     * Every entry the sources say should exist for one profile.
     *
     * @return array<string, TimelineProjection>
     */
    private function desiredEntries(string $profileId): array
    {
        $desired = [];

        foreach (HealthMeasurement::query()->where('profile_id', $profileId)->get() as $source) {
            $this->add($desired, TimelineSource::forMeasurement($source));
        }

        foreach (HealthCondition::query()->where('profile_id', $profileId)->get() as $source) {
            $this->add($desired, TimelineSource::forCondition($source));
        }

        foreach (Allergy::query()->where('profile_id', $profileId)->get() as $source) {
            $this->add($desired, TimelineSource::forAllergy($source));
        }

        foreach (MedicationPlan::query()->where('profile_id', $profileId)->get() as $source) {
            $this->add($desired, TimelineSource::forMedicationPlan($source));
        }

        foreach (MedicationRecord::query()->where('profile_id', $profileId)->get() as $source) {
            $this->add($desired, TimelineSource::forMedicationRecord($source));
        }

        $events = TaskEvent::query()
            ->whereHas('task', fn (Builder $task) => $task->where('profile_id', $profileId))
            ->with('task')
            ->get();

        foreach ($events as $event) {
            $this->add($desired, TimelineSource::forTaskEvent($event, $event->task));
        }

        foreach (Appointment::query()->where('profile_id', $profileId)->get() as $source) {
            $this->add($desired, TimelineSource::forAppointment($source));
        }

        return $desired;
    }

    /**
     * @param  array<string, TimelineProjection>  $desired
     * @param  list<TimelineProjection>  $projections
     */
    private function add(array &$desired, array $projections): void
    {
        foreach ($projections as $projection) {
            $desired[$this->key(
                $projection->type,
                $projection->refType,
                $projection->refId,
            )] = $projection;
        }
    }

    private function write(
        string $tenantId,
        string $workspaceId,
        string $profileId,
        TimelineProjection $projection,
    ): void {
        $this->projector
            ->forSubject($tenantId, $workspaceId, $profileId)
            ->record(
                $projection->type,
                $projection->refType,
                $projection->refId,
                $projection->occurredAt,
                $projection->summaryKey,
                $projection->metadata,
            );
    }

    private function differs(TimelineEntry $entry, TimelineProjection $projection): bool
    {
        return (string) $entry->summary_key !== $projection->summaryKey
            || $entry->metadata != $projection->metadata
            || $entry->occurred_at === null
            || ! $entry->occurred_at->equalTo(Carbon::parse($projection->occurredAt));
    }

    private function key(string $type, string $refType, string $refId): string
    {
        return $type.'|'.$refType.'|'.$refId;
    }

    /**
     * @param  array<string, array{created: int, updated: int, removed: int}>  $stats
     */
    private function bump(array &$stats, string $type, string $counter): void
    {
        if (! isset($stats[$type])) {
            $stats[$type] = ['created' => 0, 'updated' => 0, 'removed' => 0];
        }

        $stats[$type][$counter]++;
    }

    /**
     * @return array<string, array{created: int, updated: int, removed: int}>
     */
    private function emptyStats(): array
    {
        $stats = [];

        foreach (TimelineType::values() as $type) {
            $stats[$type] = ['created' => 0, 'updated' => 0, 'removed' => 0];
        }

        return $stats;
    }
}
