<?php

namespace App\Modules\Timeline\Observers;

use App\Modules\CareCrm\Models\Appointment;
use App\Modules\Timeline\Application\TimelineSource;

/**
 * Scheduling an appointment projects `appointment.created`; confirming
 * attendance projects a second entry under its own ref type so the visit's
 * planning and its outcome both stay visible.
 *
 * `created` only emits the planning entry even if a row is inserted with a
 * terminal status (seed/import), because no attendance action occurred.
 */
final class AppointmentObserver extends TimelineObserver
{
    public function created(Appointment $appointment): void
    {
        $this->defer(
            (string) $appointment->tenant_id,
            (string) $appointment->workspace_id,
            (string) $appointment->profile_id,
            [TimelineSource::appointmentCreated($appointment)],
        );
    }

    public function updated(Appointment $appointment): void
    {
        if (
            ! $appointment->wasChanged('status')
            || $appointment->status !== Appointment::STATUS_ATTENDED
        ) {
            return;
        }

        $this->defer(
            (string) $appointment->tenant_id,
            (string) $appointment->workspace_id,
            (string) $appointment->profile_id,
            [TimelineSource::appointmentAttended($appointment)],
        );
    }
}
