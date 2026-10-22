<?php

namespace App\Modules\Authz;

/**
 * Read-only view over `config/authorization.php`.
 *
 * No database access: the catalog is code, so every process (HTTP, queue,
 * export, AI gateway) can make the same decision without a round trip.
 */
final class PermissionCatalog
{
    public function has(string $permission): bool
    {
        return in_array($permission, $this->all(), true);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(config('authorization.permissions', []));
    }

    public function isScopeEnabled(string $scope): bool
    {
        $scopes = config('authorization.scopes', []);
        $disabled = config('authorization.disabled_scopes', []);

        return in_array($scope, $scopes, true) && ! in_array($scope, $disabled, true);
    }

    public function allowsAction(string $action): bool
    {
        return in_array($action, config('authorization.actions', []), true);
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(config('authorization.scopes', []));
    }

    /**
     * @return list<string>
     */
    public function disabledScopes(): array
    {
        return array_values(config('authorization.disabled_scopes', []));
    }

    /**
     * @return list<string>
     */
    public function consentPurposes(): array
    {
        return array_values(config('authorization.consent_purposes', []));
    }
}
