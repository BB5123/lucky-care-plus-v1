<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Application\NotificationRecipientResolver;
use App\Modules\Notifications\Jobs\DispatchNotificationJob;
use App\Modules\Retention\Events\WorkspaceLapsed;
use App\Modules\Workspace\Models\WorkspaceMembership;

/**
 * `WorkspaceLapsed` -> `retention.export_offer` to every active member (P3b).
 *
 * The offer is in-app only: email is reserved for the legal 30/7/1-day notices,
 * and a lapse is reversible - the member is already in the app when they see
 * the read-only state. The job is marked `reachLapsedAccounts` because the
 * account is `read_only` by the time it runs, and it is forced to the in-app
 * channel so a lapsed workspace does not also produce failed push attempts.
 *
 * The payload is empty: the message is "your account is read-only; export is
 * still available". No name, no health value (N-02).
 */
final class SendLapseExportOffer
{
    public function __construct(private readonly NotificationRecipientResolver $recipients) {}

    public function handle(WorkspaceLapsed $event): void
    {
        $recipientIds = $this->activeMemberIds($event->tenantId, $event->workspaceId);

        if ($recipientIds === []) {
            return;
        }

        DispatchNotificationJob::dispatch(
            tenantId: $event->tenantId,
            workspaceId: $event->workspaceId,
            templateKey: 'retention.export_offer',
            recipientUserIds: $recipientIds,
            params: [],
            subjectType: 'workspace',
            subjectId: $event->workspaceId,
            reachLapsedAccounts: true,
            channels: ['in_app'],
        );
    }

    /**
     * @return list<int>
     */
    private function activeMemberIds(string $tenantId, string $workspaceId): array
    {
        return WorkspaceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
