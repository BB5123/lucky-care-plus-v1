<?php

namespace App\Modules\CareCrm\Events;

use Carbon\CarbonImmutable;

/**
 * An appointment row was created (including the replacement row produced by a
 * reschedule).
 *
 * Ids and timestamps only — `purpose`/`location` are encrypted free text and
 * must never travel in an event payload (APT-001, CRM-015, N-02).
 *
 * Published inside the same transaction as the appointment; the notification
 * job is queued on the same database connection, so a rollback removes both.
 */
final readonly class AppointmentScheduled
{
    public function __construct(
        public string $appointmentId,
        public string $tenantId,
        public string $workspaceId,
        public string $profileId,
        public CarbonImmutable $scheduledAt,
    ) {}
}
