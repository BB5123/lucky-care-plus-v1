<?php

namespace App\Modules\Consent\Services;

use App\Modules\Consent\Models\AccessGrant;

/**
 * Pure domain predicate: can this grant be used for the given scope + action?
 *
 * Deliberately free of HTTP/request concerns so every enforcement point
 * (HTTP, queue job, export, AI gateway) can call the same rule. A revoked or
 * expired grant is unusable immediately (CD-001, F-08).
 */
final class GrantChecker
{
    public function isUsable(AccessGrant $grant, string $scope, string $action): bool
    {
        if ($grant->status !== 'active') {
            return false;
        }

        if ($grant->revoked_at !== null) {
            return false;
        }

        $now = now();

        if ($grant->valid_from !== null && $grant->valid_from->greaterThan($now)) {
            return false;
        }

        // Fail closed: a grant with no expiry is not considered usable here.
        // The owner self-grant exception is a service-level concern, not a
        // reason to weaken the checker.
        if ($grant->expires_at === null || ! $grant->expires_at->greaterThan($now)) {
            return false;
        }

        $scopes = is_array($grant->resource_scope) ? $grant->resource_scope : [];
        $actions = is_array($grant->allowed_actions) ? $grant->allowed_actions : [];

        return in_array($scope, $scopes, true) && in_array($action, $actions, true);
    }
}
