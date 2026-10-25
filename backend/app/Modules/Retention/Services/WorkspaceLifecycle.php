<?php

namespace App\Modules\Retention\Services;

use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Retention\Events\WorkspaceLapsed;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * The account lifecycle of the retention model (RETENTION-MODEL.md sections 2-4).
 *
 * This is the only place a workspace's `retention_state` may change. Every
 * transition is audited (`retention.state`), and the service enforces the
 * arrows rather than trusting the caller:
 *
 *   active --lapse--> read_only --window--> archived --window--> scheduled_deletion --> deleted
 *      ^                    |                  |
 *      +------------- restore (payment, support, heir) -----------------+
 *
 * Rules that are deliberately in code, not in prose:
 *   - an active legal hold pins the account in `read_only` (RET-T02): the
 *     scheduled advance refuses to leave it;
 *   - `deleted` is terminal: it cannot lapse or be restored;
 *   - `scheduled_deletion` has no automatic successor in P2 - the final delete
 *     is the member's "delete now" (P3) or the archive deletion (P4), and this
 *     service refuses to mark an account deleted while its data still exists;
 *   - every call requires the matching tenant/workspace context, because holds
 *     and health-data lookups are RLS-scoped and a context-less call would
 *     silently see zero holds (fail closed, F-09).
 *
 * Windows come from `retention.state_windows_days`. Until billing assigns a
 * plan (D5) every workspace uses the Free-with-health-data floor: 30 days
 * read-only + 11 months archived, and the shorter 60-day row when the
 * workspace never held health data.
 */
final class WorkspaceLifecycle
{
    /**
     * Tables whose content makes a workspace "a health record" for the
     * 12-month floor (RETENTION-MODEL.md section 3, cases 7-8).
     *
     * @var list<string>
     */
    private const HEALTH_TABLES = [
        'health_measurements',
        'health_conditions',
        'allergies',
        'medication_records',
        'medical_documents',
    ];

    public function __construct(
        private readonly LegalHold $holds,
        private readonly SecurityAuditLogger $audit,
        private readonly ArchiveManifestService $manifests,
    ) {}

    public function state(Workspace $workspace): RetentionState
    {
        return $workspace->retentionState();
    }

    /**
     * Enter `read_only` because the subscription lapsed (payment, support
     * request, or a billing event once D5 exists). Idempotent: a workspace that
     * is already lapsed is not "lapsed again".
     */
    public function lapse(Workspace $workspace, string $reason = 'manual', ?CarbonInterface $at = null): Workspace
    {
        $this->assertContext($workspace);

        $state = $this->state($workspace);

        if ($state === RetentionState::Deleted) {
            throw new LogicException('A deleted workspace cannot lapse: there is nothing left to keep.');
        }

        if ($state !== RetentionState::Active) {
            return $workspace;
        }

        $at ??= now();

        $workspace = $this->apply(
            $workspace,
            RetentionState::ReadOnly,
            $at->copy()->addDays($this->windowDays($workspace, RetentionState::ReadOnly)),
            $reason,
            $at,
            ['lapsed_at' => $at],
        );

        // The "export offered on day 1" promise (RETENTION-MODEL.md §4 case 1)
        // is a notification concern: the listener owns the message and its
        // recipients, so the lifecycle service stays free of the channel.
        event(new WorkspaceLapsed(
            (string) $workspace->tenant_id,
            (string) $workspace->id,
            $reason,
        ));

        return $workspace;
    }

    /**
     * True when the current state's deadline has passed and a successor exists.
     */
    public function isDue(Workspace $workspace, ?CarbonInterface $now = null): bool
    {
        $expires = $workspace->retention_expires_at;

        if ($expires === null || $this->state($workspace)->nextOnExpiry() === null) {
            return false;
        }

        return $expires->lessThanOrEqualTo($now ?? now());
    }

    /**
     * Move the account one arrow forward when its window has elapsed.
     *
     * Returns the updated workspace, or `null` when nothing is due or an active
     * legal hold pins the account (a hold is never bypassed by a timer).
     */
    public function advance(Workspace $workspace, ?CarbonInterface $now = null): ?Workspace
    {
        $this->assertContext($workspace);

        $now ??= now();

        if (! $this->isDue($workspace, $now)) {
            return null;
        }

        if ($this->held($workspace)) {
            return null;
        }

        $next = $this->state($workspace)->nextOnExpiry();

        if ($next === null) {
            return null;
        }

        $workspace = $this->apply(
            $workspace,
            $next,
            $now->copy()->addDays($this->windowDays($workspace, $next)),
            'window_elapsed',
            $now,
        );

        // Entering `archived` records what the account holds before the data is
        // excluded from the app (P4a). A failure here aborts the transition: an
        // archive without an inventory would be a claim we cannot verify.
        if ($next === RetentionState::Archived) {
            $this->manifests->createFor($workspace, $now);
        }

        return $workspace;
    }

    /**
     * Return the account to `active` (payment, support request, heir request).
     * Restoring an active account is a no-op.
     */
    public function restore(Workspace $workspace, string $reason = 'support', ?CarbonInterface $at = null): Workspace
    {
        $this->assertContext($workspace);

        $state = $this->state($workspace);

        if ($state === RetentionState::Deleted) {
            throw new LogicException('A deleted workspace cannot be restored.');
        }

        if ($state === RetentionState::Active) {
            return $workspace;
        }

        return $this->apply(
            $workspace,
            RetentionState::Active,
            null,
            $reason,
            $at ?? now(),
            ['lapsed_at' => null],
        );
    }

    /**
     * Mark the account `deleted` after the scheduled countdown has ended.
     *
     * P2 does not delete the data here: the physical delete is the purge +
     * compaction pair (P1), and the archive deletion is P4. The state exists so
     * the API can answer 410 and the audit can record the end of the account.
     */
    public function markDeleted(Workspace $workspace, string $reason = 'scheduled', ?CarbonInterface $at = null): Workspace
    {
        $this->assertContext($workspace);

        $state = $this->state($workspace);

        if ($state === RetentionState::Deleted) {
            return $workspace;
        }

        if ($state !== RetentionState::ScheduledDeletion) {
            throw new LogicException(
                'Only a scheduled_deletion workspace can be marked deleted in P2 (member delete-now is P3, archive deletion is P4).'
            );
        }

        return $this->apply($workspace, RetentionState::Deleted, null, $reason, $at ?? now());
    }

    /**
     * True when any active legal hold covers the workspace (workspace-wide or
     * for one profile). A hold pins the account in `read_only`.
     */
    public function held(Workspace $workspace): bool
    {
        $this->assertContext($workspace);

        return $this->holds->activeHolds($workspace->id)->isNotEmpty();
    }

    public function windowDays(Workspace $workspace, RetentionState $state): int
    {
        $windows = (array) config('retention.state_windows_days', []);

        return match ($state) {
            RetentionState::ReadOnly => (int) ($windows['read_only'] ?? 30),
            RetentionState::Archived => (int) ($this->hasHealthData($workspace)
                ? ($windows['archived_with_health_data'] ?? 335)
                : ($windows['archived_without_health_data'] ?? 30)),
            RetentionState::ScheduledDeletion => (int) ($windows['scheduled_deletion'] ?? 30),
            default => 0,
        };
    }

    /**
     * Whether the workspace ever held health data. Drives the 12-month floor
     * versus the 60-day row; a workspace that only used coordination features
     * has nothing to protect beyond the short window.
     */
    public function hasHealthData(Workspace $workspace): bool
    {
        foreach (self::HEALTH_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::table($table)->where('workspace_id', $workspace->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function apply(
        Workspace $workspace,
        RetentionState $to,
        ?CarbonInterface $expires,
        string $reason,
        CarbonInterface $at,
        array $extra = [],
    ): Workspace {
        $from = $this->state($workspace);

        $workspace->forceFill(array_merge([
            'retention_state' => $to,
            'retention_state_changed_at' => $at,
            'retention_expires_at' => $expires,
        ], $extra))->save();

        // One audit row per transition, counts and state names only (N-02).
        $this->audit->record([
            'actor_type' => 'system',
            'action' => 'retention.state',
            'target_type' => 'workspace',
            'target_id' => $workspace->id,
            'result' => 'allow',
            'reason_code' => $reason,
            'metadata' => [
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
                'expires_at' => $expires?->toIso8601String(),
            ],
        ]);

        return $workspace;
    }

    private function assertContext(Workspace $workspace): void
    {
        if (TenantContext::workspaceId() !== $workspace->id) {
            throw new LogicException(
                'Workspace lifecycle changes require the matching tenant/workspace context (N-01/F-09).'
            );
        }
    }
}
