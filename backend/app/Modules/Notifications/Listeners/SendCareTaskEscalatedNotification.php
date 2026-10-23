<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\CareCrm\Events\CareTaskEscalated;
use App\Modules\Notifications\Application\NotificationRecipientResolver;
use App\Modules\Notifications\Jobs\DispatchNotificationJob;

/**
 * `CareTaskEscalated` → `task.escalated` to the policy's recipients.
 *
 * Recipients come from the target policy, never from a blanket "everyone in
 * the workspace": the primary assignee when `notify_assignee` is set, plus the
 * active members holding any role in `notify_roles`. A policy that resolves to
 * nobody sends nothing.
 *
 * The payload is exactly `{escalation_level, due_at}` (ES-002): no title, no
 * description, no person name, no health value.
 */
final class SendCareTaskEscalatedNotification
{
    public function __construct(private readonly NotificationRecipientResolver $recipients) {}

    public function handle(CareTaskEscalated $event): void
    {
        $recipients = [];

        if ($event->notifyAssignee) {
            $assignee = $this->recipients->taskPrimaryAssigneeUserId(
                $event->taskId,
                $event->tenantId,
                $event->workspaceId,
            );

            if ($assignee !== null) {
                $recipients[$assignee] = true;
            }
        }

        foreach ($this->recipients->activeMembersWithRoles(
            $event->notifyRoles,
            $event->tenantId,
            $event->workspaceId,
        ) as $memberId) {
            $recipients[$memberId] = true;
        }

        $recipientIds = array_map('intval', array_keys($recipients));

        if ($recipientIds === []) {
            return;
        }

        DispatchNotificationJob::dispatch(
            tenantId: $event->tenantId,
            workspaceId: $event->workspaceId,
            templateKey: 'task.escalated',
            recipientUserIds: $recipientIds,
            params: [
                'escalation_level' => $event->escalationLevel,
                'due_at' => $event->dueAt?->toIso8601String() ?? '',
            ],
            subjectType: 'care_task',
            subjectId: $event->taskId,
        );
    }
}
