<?php

namespace App\Modules\CareCrm\Domain;

use App\Modules\CareCrm\Models\Appointment;

/**
 * Appointment lifecycle (`.ai/05 §8` APT-002, `.ai/CRM_DOMAIN_ARCHITECTURE §4.3`).
 *
 * ```
 * planned → confirmed → attended → needs_followup
 *    │           │
 *    ├───────────┴→ rescheduled | cancelled
 *    │           └→ missed          (confirmed only)
 * ```
 *
 * Pure predicate: version checks, actor authorization, and follow-up creation
 * live in `AppointmentService`. A transition that is not listed here is a
 * domain conflict (409), never silently accepted.
 */
final class AppointmentStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        Appointment::STATUS_PLANNED => [
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_RESCHEDULED,
            Appointment::STATUS_CANCELLED,
        ],
        Appointment::STATUS_CONFIRMED => [
            Appointment::STATUS_ATTENDED,
            Appointment::STATUS_RESCHEDULED,
            Appointment::STATUS_CANCELLED,
            Appointment::STATUS_MISSED,
        ],
        Appointment::STATUS_ATTENDED => [
            Appointment::STATUS_NEEDS_FOLLOWUP,
        ],
        Appointment::STATUS_NEEDS_FOLLOWUP => [],
        Appointment::STATUS_RESCHEDULED => [],
        Appointment::STATUS_CANCELLED => [],
        Appointment::STATUS_MISSED => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @throws DomainRuleException
     */
    public function assertAllows(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw new DomainRuleException('The requested appointment transition is not allowed.');
        }
    }
}
