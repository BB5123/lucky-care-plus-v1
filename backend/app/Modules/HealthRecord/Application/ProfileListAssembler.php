<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Authz\Authorizer;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Read model for the profile picker: "which profiles may this actor enter?".
 *
 * The health and appointment screens need a `profile_id`, but the only list
 * the client had was the dashboard's family card, which is gated by
 * `person.read`. A caregiver/viewer who holds a grant on a profile but cannot
 * read the family card therefore had no way into data they are entitled to.
 *
 * This endpoint answers that question without inventing a parallel
 * authorization rule:
 *  - visibility is decided per profile by `Authorizer::authorizeBatch` with
 *    `health_profile.read`, exactly like the dashboard's card batch;
 *  - the controller never compares roles itself;
 *  - only non-archived profiles are candidates;
 *  - the projection is a whitelist (`id`, `person_id`, `display_name`,
 *    `relationship`, `kind`) — no birth date, national id, phone, or health
 *    data enters the payload (N-02).
 *
 * `relationship` is actor-relative:
 *  - `self`      — the profile's person is linked to the authenticated actor;
 *  - `dependent` — the actor is the manager of the profile/person;
 *  - `other`     — readable through a grant on an unrelated person.
 *
 * `kind` is actor-independent: `self` when the person owns a user account,
 * `dependent` when the person is managed on someone else's behalf.
 */
final class ProfileListAssembler
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @return list<array{
     *     id: string,
     *     person_id: string,
     *     display_name: string,
     *     relationship: string,
     *     kind: string,
     * }>
     */
    public function assemble(Workspace $workspace, User $actor): array
    {
        $profiles = $this->profiles($workspace);

        if ($profiles->isEmpty()) {
            return [];
        }

        $decisions = $this->authorizer->authorizeBatch(
            $actor,
            $workspace,
            $this->checks($profiles),
        );

        $actorPersonIds = $this->actorPersonIds($workspace, $actor);

        $rows = [];

        foreach ($profiles as $index => $profile) {
            if (! $decisions[$index]->allowed()) {
                continue;
            }

            $person = $profile->person;

            if ($person === null) {
                continue;
            }

            $relationship = $this->relationship($profile, $person, $actor, $actorPersonIds);

            $rows[] = [
                'id' => (string) $profile->id,
                'person_id' => (string) $profile->person_id,
                'display_name' => (string) $person->display_name,
                'relationship' => $relationship,
                // A person with a user account is a self profile; a dependent
                // is managed on their behalf and has no account of their own.
                'kind' => $person->user_id === null ? 'dependent' : 'self',
            ];
        }

        $this->sort($rows);

        return $rows;
    }

    /**
     * @return Collection<int, HealthProfile>
     */
    private function profiles(Workspace $workspace): Collection
    {
        return HealthProfile::query()
            ->where('workspace_id', $workspace->id)
            ->notArchived()
            ->with('person')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * One batch proves every `(health_profile.read, profile)` pair. The checks
     * are built in query order so the decisions map back by index.
     *
     * @param  Collection<int, HealthProfile>  $profiles
     * @return list<array{permission: string, profile_id: ?string}>
     */
    private function checks(Collection $profiles): array
    {
        return $profiles
            ->map(static fn (HealthProfile $profile): array => [
                'permission' => 'health_profile.read',
                'profile_id' => (string) $profile->id,
            ])
            ->values()
            ->all();
    }

    /**
     * The actor's own person rows in this workspace, used to recognise the
     * dependents they manage without trusting anything from the client.
     *
     * @return array<string, true>
     */
    private function actorPersonIds(Workspace $workspace, User $actor): array
    {
        return array_fill_keys(
            Person::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $actor->id)
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all(),
            true,
        );
    }

    /**
     * @param  array<string, true>  $actorPersonIds
     */
    private function relationship(
        HealthProfile $profile,
        Person $person,
        User $actor,
        array $actorPersonIds,
    ): string {
        if ((string) $person->user_id === (string) $actor->id) {
            return 'self';
        }

        $managers = array_filter([
            $profile->managed_by_person_id,
            $person->managed_by_person_id,
        ]);

        foreach ($managers as $managerId) {
            if (isset($actorPersonIds[(string) $managerId])) {
                return 'dependent';
            }
        }

        return 'other';
    }

    /**
     * Deterministic order: the actor's own profile first, then dependents by
     * display name, then profiles readable only through a grant. The person
     * and profile ids are final tie-breakers so two people with the same name
     * never swap places between requests.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function sort(array &$rows): void
    {
        $rank = ['self' => 0, 'dependent' => 1, 'other' => 2];

        usort($rows, static function (array $left, array $right) use ($rank): int {
            return [
                $rank[$left['relationship']] ?? 9,
                $left['display_name'],
                $left['person_id'],
                $left['id'],
            ] <=> [
                $rank[$right['relationship']] ?? 9,
                $right['display_name'],
                $right['person_id'],
                $right['id'],
            ];
        });
    }
}
