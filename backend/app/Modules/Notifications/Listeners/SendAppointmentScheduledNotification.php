<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\CareCrm\Events\AppointmentScheduled;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\Notifications\Application\NotificationRecipientResolver;
use App\Modules\Notifications\Jobs\DispatchNotificationJob;

/**
 * `AppointmentScheduled` → `appt.reminder` to the profile owner, plus the
 * active primary assignee of the linked transport task when one is set.
 *
 * The transport link is read from the appointment inside the same transaction
 * rather than widened onto the event, so the event itself stays ids + time.
 * A recipient that cannot be resolved is skipped — never replaced by every
 * workspace member.
 */
final class SendAppointmentScheduledNotification
{
    public function __construct(private readonly NotificationRecipientResolver $recipients) {}

    public function handle(AppointmentScheduled $event): void
    {
        $appointment = Appointment::query()
            ->where('tenant_id', $event->tenantId)
            ->where('workspace_id', $event->workspaceId)
            ->whereKey($event->appointmentId)
            ->first();

        $recipients = [];

        $owner = $this->recipients->profileOwnerUserId(
            $event->profileId,
            $event->tenantId,
            $event->workspaceId,
        );

        if ($owner !== null) {
            $recipients[$owner] = true;
        }

        if ($appointment?->transported_by_task_id !== null) {
            $transport = $this->recipients->taskPrimaryAssigneeUserId(
                $appointment->transported_by_task_id,
                $event->tenantId,
                $event->workspaceId,
            );

            if ($transport !== null) {
                $recipients[$transport] = true;
            }
        }

        $recipientIds = array_map('intval', array_keys($recipients));

        if ($recipientIds === []) {
            return;
        }

        DispatchNotificationJob::dispatch(
            tenantId: $event->tenantId,
            workspaceId: $event->workspaceId,
            templateKey: 'appt.reminder',
            recipientUserIds: $recipientIds,
            params: [
                'appointment_id' => $event->appointmentId,
                'scheduled_at' => $event->scheduledAt->toIso8601String(),
            ],
            subjectType: 'appointment',
            subjectId: $event->appointmentId,
        );
    }
}
