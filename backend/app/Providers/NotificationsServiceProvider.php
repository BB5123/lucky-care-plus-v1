<?php

namespace App\Providers;

use App\Modules\CareCrm\Events\AppointmentScheduled;
use App\Modules\CareCrm\Events\CareTaskAssigned;
use App\Modules\Notifications\Listeners\SendAppointmentScheduledNotification;
use App\Modules\Notifications\Listeners\SendCareTaskAssignedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires CareCrm domain events to the notification fan-out (P1.6b).
 *
 * Registration lives in one place so the "which events notify?" question is
 * answered by reading a short list, not by grepping the domain services.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(CareTaskAssigned::class, SendCareTaskAssignedNotification::class);
        Event::listen(AppointmentScheduled::class, SendAppointmentScheduledNotification::class);
    }
}
