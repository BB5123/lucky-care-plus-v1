<?php

namespace App\Modules\Timeline\Domain;

/**
 * Maps a timeline entry to the source-data scope that owns it.
 *
 * `timeline.read` is not a shortcut to those scopes (CRM-013 / F-06). The
 * endpoint must re-prove the owning scope per entry before showing its detail,
 * so this table is the single place that answers "which scope is this?".
 */
final class TimelineScope
{
    public const VITALS = 'vitals';

    public const MEDICATIONS = 'medications';

    public const APPOINTMENTS = 'appointments';

    public const TASKS = 'tasks';

    public const PROFILE_BASIC = 'profile.basic';

    /**
     * @var array<string, string>
     */
    private const BY_TYPE = [
        TimelineType::MEASUREMENT => self::VITALS,
        TimelineType::CONDITION => self::PROFILE_BASIC,
        TimelineType::ALLERGY => self::PROFILE_BASIC,
        TimelineType::MEDICATION_PLAN => self::MEDICATIONS,
        TimelineType::MEDICATION_RECORD => self::MEDICATIONS,
        TimelineType::CARE_TASK => self::TASKS,
        TimelineType::APPOINTMENT => self::APPOINTMENTS,
    ];

    /**
     * One representative read permission per scope for the per-request batch
     * check. Every permission below maps to exactly that scope in
     * `Authorizer::PERMISSION_MAP`, so the scope decision is unchanged while a
     * page still runs at most one authorization per scope.
     *
     * @var array<string, string>
     */
    private const PERMISSION_BY_SCOPE = [
        self::VITALS => 'health_measurement.read',
        self::MEDICATIONS => 'medication_plan.read',
        self::APPOINTMENTS => 'appointment.read',
        self::TASKS => 'care_task.read',
        self::PROFILE_BASIC => 'health_profile.read',
    ];

    public static function forType(string $type): ?string
    {
        return self::BY_TYPE[$type] ?? null;
    }

    public static function permissionFor(string $scope): ?string
    {
        return self::PERMISSION_BY_SCOPE[$scope] ?? null;
    }
}
