<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\CareCrm\Events\CareTaskAssigned;
use App\Modules\Notifications\Application\NotificationRecipientResolver;
use App\Modules\Notifications\Jobs\DispatchNotificationJob;

/**
 * `CareTaskAssigned` → `task.assigned` to the assignee only.
 *
 * If the assignee is no longer an active member the event is dropped; it never
 * falls back to "all members". The actual fan-out runs in
 * {@see DispatchNotificationJob} so the request transaction only queues work.
 */
final class SendCareTaskAssignedNotification
{
    public function __construct(private readonly NotificationRecipientResolver $recipients) {}

    public function handle(CareTaskAssigned $event): void
    {
        $recipient = $this->recipients->activeMember(
            $event->assigneeUserId,
            $event->tenantId,
            $event->workspaceId,
        );

        if ($recipient === null) {
            return;
        }

        DispatchNotificationJob::dispatch(
            tenantId: $event->tenantId,
            workspaceId: $event->workspaceId,
            templateKey: 'task.assigned',
            recipientUserIds: [$recipient],
            params: [
                'task_id' => $event->taskId,
                'due_at' => $event->dueAt?->toIso8601String() ?? '',
                'tier' => $event->tier,
            ],
            subjectType: 'care_task',
            subjectId: $event->taskId,
        );
    }
}
