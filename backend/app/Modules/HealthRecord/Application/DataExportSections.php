<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Authz\Authorizer;
use App\Modules\Authz\ResourceRef;

/**
 * Section-level authorization for one profile's data export.
 *
 * E8 (`.ai/03-AUTHORIZATION.md`): an export counts only the rows the requester
 * may read. Each section therefore maps to the exact permission ID the rest of
 * the API already declares for that data in `routes/api.php`; no section may
 * invent a role check of its own.
 *
 * The same decision is used before a row is created (so a request that could
 * only produce an empty file fails with 403), while the export is built, and
 * again before a signed link is minted (E7/F-08).
 */
final class DataExportSections
{
    /**
     * Export section => permission ID declared by the matching API route.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'profile' => 'health_profile.read',
        'conditions' => 'health_condition.read',
        'allergies' => 'allergy.read',
        'measurements' => 'health_measurement.read',
        'medication_plans' => 'medication_plan.read',
        'medication_records' => 'medication_record.read',
        'appointments' => 'appointment.read',
        'care_tasks' => 'care_task.read',
        'task_events' => 'care_task.read',
        'documents' => 'medical_document.read',
    ];

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /**
     * Split the requested sections into readable and withheld ones.
     *
     * @param  list<string>|null  $only  Null checks every declared section.
     * @return array{included: list<string>, withheld: list<array{section: string, reason: string}>}
     */
    public function decide(
        User $actor,
        string $workspaceId,
        string $profileId,
        ?array $only = null,
    ): array {
        $included = [];
        $withheld = [];

        // `care_tasks` and `task_events` share one permission. Cache the
        // decision so one permission check writes one audit row per export,
        // not one row per section that happens to use it.
        $decisions = [];

        foreach ($only ?? self::all() as $section) {
            $permission = self::PERMISSIONS[$section] ?? null;

            if ($permission === null) {
                // A stored section that no longer exists must not be treated
                // as readable. Fail closed.
                $withheld[] = ['section' => $section, 'reason' => 'not_permitted'];

                continue;
            }

            $decisions[$permission] ??= $this->authorizer->authorize(
                $actor,
                $permission,
                ResourceRef::forProfile($workspaceId, $profileId),
            );

            if ($decisions[$permission]->allowed()) {
                $included[] = $section;

                continue;
            }

            // Deliberately coarse: the reason is rendered into the user's
            // export and must not mirror internal denial codes.
            $withheld[] = ['section' => $section, 'reason' => 'not_permitted'];
        }

        return ['included' => $included, 'withheld' => $withheld];
    }
}
