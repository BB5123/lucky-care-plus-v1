<?php

namespace App\Modules\Timeline\Application;

use App\Models\User;
use App\Modules\Authz\Authorizer;
use App\Modules\Timeline\Domain\TimelineScope;
use App\Modules\Timeline\Models\TimelineEntry;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Per-entry visibility for one timeline page (CRM-013 / CRM-014).
 *
 * `timeline.read` proves only that the actor may read the timeline surface; it
 * is explicitly **not** a gate to the underlying scopes (`.ai/03 §5`). For
 * every entry the owning scope is checked again through the single Authorizer
 * entry point, so a `timeline`-only reader sees events but never details.
 *
 * Checks are batched per scope for the page — usually one to five checks —
 * while every entry is still decided individually by its own scope. That is
 * the same rule as "one check per entry", without paying a full pipeline and
 * audit append per row.
 */
final class TimelineVisibility
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return array<string, bool> keyed by entry id
     */
    public function visibleEntryIds(
        Workspace $workspace,
        User $actor,
        string $profileId,
        Collection $entries,
    ): array {
        $checks = [];
        $indexByPermission = [];

        foreach ($entries as $entry) {
            $permission = $this->permissionFor((string) $entry->type);

            if ($permission === null || isset($indexByPermission[$permission])) {
                continue;
            }

            $indexByPermission[$permission] = count($checks);
            $checks[] = ['permission' => $permission, 'profile_id' => $profileId];
        }

        $decisions = $checks === []
            ? []
            : $this->authorizer->authorizeBatch($actor, $workspace, $checks);

        $allowedByPermission = [];

        foreach ($indexByPermission as $permission => $index) {
            $allowedByPermission[$permission] = $decisions[$index]->allowed();
        }

        $visible = [];

        foreach ($entries as $entry) {
            $permission = $this->permissionFor((string) $entry->type);
            $visible[(string) $entry->id] = $permission !== null
                && ($allowedByPermission[$permission] ?? false);
        }

        return $visible;
    }

    private function permissionFor(string $type): ?string
    {
        $scope = TimelineScope::forType($type);

        return $scope === null ? null : TimelineScope::permissionFor($scope);
    }
}
