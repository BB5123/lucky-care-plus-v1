<?php

namespace App\Modules\Retention\Services;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workspace inactivity flow (ADR-016 §2 / DM-R08).
 *
 * A workspace is inactive when none of its active members has logged in for
 * `retention.inactivity_months` months. The last login comes from
 * `users.last_login_at`; a workspace with no recorded login falls back to its
 * creation time so a brand-new workspace is not immediately purge-due.
 *
 * Reminders are due 30 / 14 / 7 days before the purge date.
 *
 * NOTIFICATION STUB: the Notifications module does not exist yet, so
 * `dispatchDueNotifications()` only writes a structured log line. There is no
 * delivery ledger, therefore it cannot de-duplicate reminders — that arrives
 * with the Notifications module. Nothing here sends PHI (N-02).
 */
final class InactivityService
{
    /**
     * @return list<InactivityStatus>
     */
    public function scan(?CarbonInterface $now = null): array
    {
        $now = $now === null ? Carbon::now()->utc() : Carbon::instance($now)->utc();
        $months = (int) config('retention.inactivity_months', 24);
        $offsets = array_map('intval', (array) config('retention.notification_days_before', [30, 14, 7]));

        $statuses = [];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            DB::transaction(function () use ($tenantId, $months, $offsets, $now, &$statuses): void {
                $workspaces = TenantContext::run(
                    $tenantId,
                    '',
                    // A lapsed/archived account already has a retention deadline;
                    // an inactivity reminder would be a second, contradictory one.
                    fn () => Workspace::query()->acceptingBackgroundWork()->orderBy('id')->get(),
                );

                foreach ($workspaces as $workspace) {
                    $status = TenantContext::run(
                        $tenantId,
                        $workspace->id,
                        fn (): InactivityStatus => $this->statusForWorkspace(
                            $workspace,
                            $months,
                            $offsets,
                            $now,
                        ),
                    );

                    $statuses[] = $status;
                }
            });
        }

        return $statuses;
    }

    /**
     * Workspaces that have passed the inactivity period and may be purged.
     *
     * @return list<InactivityStatus>
     */
    public function dueNow(?CarbonInterface $now = null): array
    {
        return array_values(array_filter(
            $this->scan($now),
            fn (InactivityStatus $status): bool => $status->purgeDue,
        ));
    }

    /**
     * Notification stub: log the reminders that are due. Returns how many
     * would have been sent. A real `Notifications` implementation will replace
     * the body without changing the call site.
     *
     * @param  list<InactivityStatus>  $statuses
     */
    public function dispatchDueNotifications(array $statuses, ?CarbonInterface $now = null): int
    {
        $now = $now === null ? Carbon::now()->utc() : Carbon::instance($now)->utc();
        $sent = 0;

        foreach ($statuses as $status) {
            foreach ($status->schedule as $entry) {
                if ($entry['due_at']->greaterThan($now)) {
                    continue;
                }

                Log::info('retention.inactivity.notification_due', [
                    'tenant_id' => $status->tenantId,
                    'workspace_id' => $status->workspaceId,
                    'days_before' => $entry['days_before'],
                    'purge_due_at' => $status->purgeDueAt->toIso8601String(),
                ]);

                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  list<int>  $offsets
     */
    private function statusForWorkspace(
        Workspace $workspace,
        int $months,
        array $offsets,
        CarbonInterface $now,
    ): InactivityStatus {
        $userIds = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->pluck('user_id');

        $lastLogin = $userIds->isEmpty()
            ? null
            : User::query()->whereIn('id', $userIds)->max('last_login_at');

        $lastActivity = $lastLogin === null
            ? Carbon::instance($workspace->created_at)->utc()
            : Carbon::parse($lastLogin)->utc();

        $purgeDueAt = $lastActivity->copy()->addMonthsNoOverflow($months);

        $schedule = [];
        foreach ($offsets as $days) {
            $schedule[] = [
                'days_before' => $days,
                'due_at' => $purgeDueAt->copy()->subDays($days),
            ];
        }

        $notificationsDue = array_values(array_map(
            fn (array $entry): int => $entry['days_before'],
            array_filter($schedule, fn (array $entry): bool => $entry['due_at']->lessThanOrEqualTo($now)),
        ));

        return new InactivityStatus(
            tenantId: $workspace->tenant_id,
            workspaceId: $workspace->id,
            lastActivityAt: $lastActivity,
            purgeDueAt: $purgeDueAt,
            schedule: $schedule,
            purgeDue: $now->greaterThanOrEqualTo($purgeDueAt),
            notificationsDue: $notificationsDue,
        );
    }
}
