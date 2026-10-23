<?php

namespace App\Modules\CareCrm\Application;

use App\Modules\CareCrm\Events\CareTaskEscalated;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\EscalationPolicy;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Walks the escalation ladder for overdue care tasks (`.ai/05 §6`).
 *
 * Rules:
 *  - only non-terminal `delegated` / `clinical` tasks with a past `due_at` are
 *    candidates; `routine` work is never escalated (one-tap chores do not need
 *    a ladder);
 *  - a `blocked` task whose `review_at` is still in the future is skipped —
 *    somebody is already working on it;
 *  - the target is the **highest active policy whose `after_minutes` has
 *    elapsed**, so a task that is three hours late jumps straight to the level
 *    that is due instead of firing every rung in one run;
 *  - `task.escalation_level >= target` is a no-op, which makes repeated runs
 *    (and overlapping schedulers) idempotent;
 *  - one `escalated` task event + one business audit row are written per climb;
 *  - `reassign_to_admin` moves the primary assignment through
 *    {@see CareTaskTransitionService::reassignBySystem()} first, so the
 *    responsibility history is real (CT-007), then records the reassignment in
 *    the same escalation event metadata;
 *  - a workspace with no active policies escalates nothing (fail-safe).
 *
 * The escalator scans the tenant directory because a scheduler has no request
 * context. Every workspace runs inside its own trusted `TenantContext` window
 * and transaction, exactly like the recurring materialiser.
 */
final class CareTaskEscalator
{
    public function __construct(
        private readonly CareTaskTransitionService $transitions,
        private readonly CareTaskEventRecorder $events,
    ) {}

    /**
     * @return array{scanned: int, escalated: int, skipped: int, reassigned: int}
     */
    public function run(int $limit = 200, bool $dryRun = false): array
    {
        $limit = max(1, $limit);
        $remaining = $limit;
        $summary = ['scanned' => 0, 'escalated' => 0, 'skipped' => 0, 'reassigned' => 0];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            if ($remaining <= 0) {
                break;
            }

            $tenantId = (string) $tenantId;

            $partial = DB::transaction(function () use ($tenantId, &$remaining, $dryRun): array {
                $result = ['scanned' => 0, 'escalated' => 0, 'skipped' => 0, 'reassigned' => 0];

                TenantContext::run($tenantId, '', function () use ($tenantId, &$remaining, $dryRun, &$result): void {
                    $workspaces = Workspace::query()
                        ->where('tenant_id', $tenantId)
                        ->orderBy('id')
                        ->get();

                    foreach ($workspaces as $workspace) {
                        if ($remaining <= 0) {
                            break;
                        }

                        $batch = TenantContext::run(
                            $tenantId,
                            (string) $workspace->id,
                            fn (): array => $this->runWorkspace($workspace, $remaining, $dryRun),
                        );

                        $remaining -= $batch['scanned'];
                        $result['scanned'] += $batch['scanned'];
                        $result['escalated'] += $batch['escalated'];
                        $result['skipped'] += $batch['skipped'];
                        $result['reassigned'] += $batch['reassigned'];
                    }
                });

                return $result;
            });

            $summary['scanned'] += $partial['scanned'];
            $summary['escalated'] += $partial['escalated'];
            $summary['skipped'] += $partial['skipped'];
            $summary['reassigned'] += $partial['reassigned'];
        }

        return $summary;
    }

    /**
     * @return array{scanned: int, escalated: int, skipped: int, reassigned: int}
     */
    private function runWorkspace(Workspace $workspace, int $limit, bool $dryRun): array
    {
        /** @var Collection<int, EscalationPolicy> $policies */
        $policies = EscalationPolicy::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->orderBy('after_minutes')
            ->orderBy('level')
            ->get();

        $result = ['scanned' => 0, 'escalated' => 0, 'skipped' => 0, 'reassigned' => 0];

        if ($policies->isEmpty()) {
            // Deny by default: a workspace that never configured a ladder
            // escalates nothing (and writes nothing).
            return $result;
        }

        $now = now();

        $candidates = CareTask::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->whereIn('tier', [CareTask::TIER_DELEGATED, CareTask::TIER_CLINICAL])
            ->whereNotIn('status', [CareTask::STATUS_COMPLETED, CareTask::STATUS_CANCELLED])
            ->where('escalation_level', '<', (int) $policies->max('level'))
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($candidates as $candidate) {
            $result['scanned']++;

            $outcome = $this->escalate(
                $workspace,
                (string) $candidate->id,
                $policies,
                $dryRun,
            );

            if ($outcome === null) {
                $result['skipped']++;

                continue;
            }

            $result['escalated']++;

            if ($outcome['reassigned']) {
                $result['reassigned']++;
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, EscalationPolicy>  $policies
     * @return array{reassigned: bool}|null null when the task must be skipped
     */
    private function escalate(
        Workspace $workspace,
        string $taskId,
        Collection $policies,
        bool $dryRun,
    ): ?array {
        return DB::transaction(function () use ($workspace, $taskId, $policies, $dryRun): ?array {
            $task = CareTask::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (! $task instanceof CareTask) {
                return null;
            }

            $now = now();
            $target = $this->targetLevel($task, $policies, $now);

            if ($target === 0 || $task->escalation_level >= $target) {
                return null;
            }

            /** @var EscalationPolicy|null $policy */
            $policy = $policies->firstWhere('level', $target);

            if (! $policy instanceof EscalationPolicy) {
                return null;
            }

            $reassign = $policy->action === EscalationPolicy::ACTION_REASSIGN_TO_ADMIN;

            if ($dryRun) {
                // Report the decision but do not lock in any side effect.
                return ['reassigned' => $reassign && $this->workspaceAdminUserId($workspace) !== null];
            }

            $from = (int) $task->escalation_level;
            $reassignedUserId = null;

            if ($reassign) {
                $reassignedUserId = $this->workspaceAdminUserId($workspace);

                if ($reassignedUserId !== null) {
                    $task = $this->transitions->reassignBySystem(
                        $workspace,
                        (string) $task->id,
                        $reassignedUserId,
                        (int) $task->version,
                        ['escalation_level' => $target],
                    );
                }
            }

            $task->escalation_level = $target;
            $task->version = (int) $task->version + 1;
            $task->save();

            $metadata = ['from' => $from, 'to' => $target];

            if ($reassignedUserId !== null) {
                $metadata['reassigned_user_id'] = $reassignedUserId;
            }

            $this->events->record(
                $task,
                null,
                'escalated',
                $task->status,
                $task->status,
                'care_task.escalate',
                null,
                $metadata,
            );

            // Escalation carries no PHI: the listener resolves recipients from
            // the policy flags and the dispatcher only receives
            // `{escalation_level, due_at}` (ES-002).
            event(new CareTaskEscalated(
                taskId: (string) $task->id,
                tenantId: (string) $task->tenant_id,
                workspaceId: (string) $task->workspace_id,
                profileId: (string) $task->profile_id,
                escalationLevel: $target,
                notifyAssignee: (bool) $policy->notify_assignee,
                notifyRoles: array_values(array_map('strval', $policy->notify_roles ?? [])),
                dueAt: $task->due_at?->toImmutable(),
            ));

            return ['reassigned' => $reassignedUserId !== null];
        });
    }

    /**
     * Highest active policy whose delay has elapsed since `due_at`.
     *
     * @param  Collection<int, EscalationPolicy>  $policies
     */
    private function targetLevel(CareTask $task, Collection $policies, CarbonInterface $now): int
    {
        if ($task->due_at === null || CareTask::isTerminal($task->status)) {
            return 0;
        }

        if (! in_array($task->tier, [CareTask::TIER_DELEGATED, CareTask::TIER_CLINICAL], true)) {
            return 0;
        }

        // A blocked task with a future review is being worked on; do not nag.
        if (
            $task->status === CareTask::STATUS_BLOCKED
            && $task->review_at !== null
            && $task->review_at->isFuture()
        ) {
            return 0;
        }

        $target = 0;

        foreach ($policies as $policy) {
            $firesAt = CarbonImmutable::instance($task->due_at)->addMinutes((int) $policy->after_minutes);

            if ($firesAt->lessThanOrEqualTo($now)) {
                $target = max($target, (int) $policy->level);
            }
        }

        return $target;
    }

    private function workspaceAdminUserId(Workspace $workspace): ?int
    {
        $userId = WorkspaceMembership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->where('role', 'workspace_admin')
            ->orderBy('id')
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }
}
