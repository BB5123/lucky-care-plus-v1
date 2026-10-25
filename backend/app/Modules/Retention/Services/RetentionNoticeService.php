<?php

namespace App\Modules\Retention\Services;

use App\Modules\Notifications\Application\NotificationDeliveryLedger;
use App\Modules\Notifications\Jobs\DispatchNotificationJob;
use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The 30/7/1-day notices for an account scheduled for deletion (P3, R7).
 *
 * A workspace enters `scheduled_deletion` with a 30-day deadline. This service
 * finds the most urgent notice that has become due and has not been delivered
 * yet, and dispatches it to every active member through the notification
 * pipeline - in-app always, email as well.
 *
 * Deliberate choices:
 *  - **One notice per workspace per run.** A missed nightly run does not make
 *    three mails arrive at once; the most urgent due notice is sent, and the
 *    ones already passed count as missed rather than replayed. On the normal
 *    schedule all three fire (day 30, day 7, day 1).
 *  - **Idempotent through the delivery ledger**, keyed by template + workspace id
 *    as the subject. A second run the same night sends nothing.
 *  - **No preference override for in-app/email**, because these are legal
 *    notices: a member cannot opt out of being told their account will be
 *    deleted, and the email channel is forced for that reason only.
 *  - **No health data and no name** in the payload: the only placeholder is
 *    `days_left` (N-02).
 */
final class RetentionNoticeService
{
    /**
     * The notice thresholds, most urgent first.
     *
     * @var list<array{days: int, template: string}>
     */
    private const NOTICES = [
        ['days' => 1, 'template' => 'retention.notice_1'],
        ['days' => 7, 'template' => 'retention.notice_7'],
        ['days' => 30, 'template' => 'retention.notice_30'],
    ];

    public function __construct(private readonly NotificationDeliveryLedger $ledger) {}

    /**
     * @return list<array{workspace_id: string, days_left: int, template: string, recipients: int, status: string}>
     */
    public function dispatchDue(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $now = ($now ?? now())->utc();
        $rows = [];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            $tenantId = (string) $tenantId;

            DB::transaction(function () use ($tenantId, $now, $dryRun, &$rows): void {
                TenantContext::run($tenantId, '', function () use ($tenantId, $now, $dryRun, &$rows): void {
                    $workspaceIds = Workspace::query()
                        ->where('retention_state', RetentionState::ScheduledDeletion->value)
                        ->whereNotNull('retention_expires_at')
                        ->orderBy('id')
                        ->pluck('id');

                    foreach ($workspaceIds as $workspaceId) {
                        TenantContext::run(
                            $tenantId,
                            (string) $workspaceId,
                            function () use ($tenantId, $workspaceId, $now, $dryRun, &$rows): void {
                                $workspace = Workspace::query()->find($workspaceId);

                                if (! $workspace instanceof Workspace || $workspace->retention_expires_at === null) {
                                    return;
                                }

                                // Calendar days, so "30 days" does not become 29
                                // because the nightly job ran a minute early.
                                $daysLeft = max(0, (int) $now->copy()->startOfDay()->diffInDays(
                                    $workspace->retention_expires_at->copy()->startOfDay(),
                                    false,
                                ));

                                $template = $this->noticeFor($daysLeft);

                                if ($template === null) {
                                    return;
                                }

                                if ($this->ledger->hasDelivery(
                                    $template,
                                    (string) $workspace->id,
                                    $tenantId,
                                    (string) $workspace->id,
                                )) {
                                    return;
                                }

                                $recipients = $this->recipientIds($workspace);

                                if ($recipients === []) {
                                    $rows[] = $this->row($workspace, $daysLeft, $template, 0, 'no_recipients');

                                    return;
                                }

                                if ($dryRun) {
                                    $rows[] = $this->row($workspace, $daysLeft, $template, count($recipients), 'would_send');

                                    return;
                                }

                                DispatchNotificationJob::dispatch(
                                    tenantId: $tenantId,
                                    workspaceId: (string) $workspace->id,
                                    templateKey: $template,
                                    recipientUserIds: $recipients,
                                    params: ['days_left' => $daysLeft],
                                    subjectType: 'workspace',
                                    subjectId: (string) $workspace->id,
                                    reachLapsedAccounts: true,
                                    channels: ['in_app', 'email'],
                                );

                                $rows[] = $this->row($workspace, $daysLeft, $template, count($recipients), 'queued');
                            },
                        );
                    }
                });
            });
        }

        return $rows;
    }

    private function noticeFor(int $daysLeft): ?string
    {
        foreach (self::NOTICES as $notice) {
            if ($daysLeft <= $notice['days']) {
                return $notice['template'];
            }
        }

        return null;
    }

    /**
     * Every active member of the account. Membership, not role: the notice is
     * about the account they all share, and restricting it to admins would hide
     * the deadline from the caregivers who actually hold the records.
     *
     * @return list<int>
     */
    private function recipientIds(Workspace $workspace): array
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{workspace_id: string, days_left: int, template: string, recipients: int, status: string}
     */
    private function row(
        Workspace $workspace,
        int $daysLeft,
        string $template,
        int $recipients,
        string $status,
    ): array {
        return [
            'workspace_id' => (string) $workspace->id,
            'days_left' => $daysLeft,
            'template' => $template,
            'recipients' => $recipients,
            'status' => $status,
        ];
    }
}
