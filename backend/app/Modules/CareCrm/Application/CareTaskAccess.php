<?php

namespace App\Modules\CareCrm\Application;

use App\Models\User;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Services\GrantChecker;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Care-task-specific visibility rules.
 *
 * This is intentionally separate from `care_task.*` permission checks: being
 * allowed to *read a task* is not the same as being allowed to read the
 * health record behind it (F-04, CRM-006). The same scope predicate used by
 * `Authorizer` (owner or a usable `access_grants` row) decides whether the full
 * DTO or the need-to-know card is returned.
 */
final class CareTaskAccess
{
    public function __construct(private readonly GrantChecker $grants) {}

    /**
     * The profile owner is the person the profile belongs to. A manager of a
     * dependent is not an owner; they need an explicit grant like everyone
     * else (F-05).
     */
    public function isOwner(User $actor, HealthProfile $profile): bool
    {
        return Person::query()
            ->whereKey($profile->person_id)
            ->where('user_id', $actor->id)
            ->exists();
    }

    public function hasScope(User $actor, HealthProfile $profile, string $scope, string $action): bool
    {
        if ($this->isOwner($actor, $profile)) {
            return true;
        }

        $grants = AccessGrant::query()
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->where('grantee_user_id', $actor->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->get();

        foreach ($grants as $grant) {
            if ($this->grants->isUsable($grant, $scope, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `tasks` grant covers the care record itself; a `vitals` grant proves
     * the actor may read the profile's health data, so CRM-006 permits the
     * full task DTO rather than only the card.
     */
    public function canReadFull(User $actor, HealthProfile $profile): bool
    {
        return $this->hasScope($actor, $profile, 'tasks', 'read')
            || $this->hasScope($actor, $profile, 'vitals', 'read');
    }

    /**
     * CR-004: evidence is the PHI-bearing artifact, so it needs its own scope.
     * A `tasks`/`vitals` read grant no longer implies it; only the owner or an
     * explicit `task_evidence:read` grant may read the evidence rows.
     */
    public function canReadEvidence(User $actor, HealthProfile $profile): bool
    {
        return $this->hasScope($actor, $profile, 'task_evidence', 'read');
    }

    public function canCreate(User $actor, HealthProfile $profile): bool
    {
        return $this->hasScope($actor, $profile, 'tasks', 'create');
    }

    public function canManage(User $actor, HealthProfile $profile): bool
    {
        return $this->hasScope($actor, $profile, 'tasks', 'update');
    }

    public function isAssigned(User $actor, CareTask $task, bool $onlyActive = true): bool
    {
        return $this->assignmentQuery($task, $onlyActive)
            ->where('user_id', $actor->id)
            ->exists();
    }

    public function isPrimary(User $actor, CareTask $task): bool
    {
        return $this->assignmentQuery($task)
            ->where('user_id', $actor->id)
            ->where('role', 'primary')
            ->exists();
    }

    /**
     * CT-002: a helper may complete only with an explicit `can_complete` flag.
     */
    public function hasCanComplete(User $actor, CareTask $task): bool
    {
        return $this->assignmentQuery($task)
            ->where('user_id', $actor->id)
            ->where('can_complete', true)
            ->exists();
    }

    public function mayComplete(User $actor, CareTask $task): bool
    {
        return $this->isPrimary($actor, $task) || $this->hasCanComplete($actor, $task);
    }

    /**
     * Profiles whose full task records this actor may read.
     *
     * @return list<string>
     */
    public function fullProfileIds(User $actor, Workspace $workspace): array
    {
        $grants = AccessGrant::query()
            ->where('workspace_id', $workspace->id)
            ->where('grantee_user_id', $actor->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->get();

        $ids = [];

        foreach ($grants as $grant) {
            if (
                $this->grants->isUsable($grant, 'tasks', 'read')
                || $this->grants->isUsable($grant, 'vitals', 'read')
            ) {
                $ids[] = (string) $grant->profile_id;
            }
        }

        $owned = HealthProfile::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('person_id', Person::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $actor->id)
                ->select('id'))
            ->pluck('id')
            ->all();

        return array_values(array_unique([...$ids, ...$owned]));
    }

    /**
     * Base query restricted to active assignments (or all rows when requested).
     *
     * @return Builder<TaskAssignment>
     */
    private function assignmentQuery(CareTask $task, bool $onlyActive = true): Builder
    {
        $query = $task->assignments()
            ->getQuery()
            ->where('task_id', $task->id)
            ->where('tenant_id', $task->tenant_id)
            ->where('workspace_id', $task->workspace_id);

        return $onlyActive ? $query->whereNull('released_at') : $query;
    }
}
