<?php

namespace App\Modules\Timeline\Domain;

/**
 * Closed vocabulary for a projected timeline entry (`.ai/02 §5`).
 *
 * The value is safe metadata: it says *what kind* of event happened, never the
 * health content behind it (CRM-012 / DM-007).
 */
final class TimelineType
{
    public const MEASUREMENT = 'measurement';

    public const CONDITION = 'condition';

    public const ALLERGY = 'allergy';

    public const MEDICATION_PLAN = 'medication_plan';

    public const MEDICATION_RECORD = 'medication_record';

    public const CARE_TASK = 'care_task';

    public const APPOINTMENT = 'appointment';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::MEASUREMENT,
            self::CONDITION,
            self::ALLERGY,
            self::MEDICATION_PLAN,
            self::MEDICATION_RECORD,
            self::CARE_TASK,
            self::APPOINTMENT,
        ];
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::values(), true);
    }
}
