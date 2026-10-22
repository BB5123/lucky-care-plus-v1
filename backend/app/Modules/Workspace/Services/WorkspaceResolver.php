<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Support\Str;

/**
 * Resolves a workspace UUID to the workspace row from trusted server data.
 *
 * Bootstrap problem: `workspaces` is protected by tenant-scoped RLS, but a
 * request arrives with only a workspace UUID. `tenants` is deliberately not
 * tenant-scoped (ADR-013), so it is used as the narrow directory that maps a
 * workspace to its tenant. The loop is a single iteration in the MVP, which
 * runs with one default tenant; it is written to stay correct if a second
 * tenant exists. Only the workspace→tenant mapping is ever read here: no
 * health/person data is touched, and callers still have to prove membership
 * before anything is returned to a client.
 */
class WorkspaceResolver
{
    public function resolve(string $workspaceId): ?Workspace
    {
        // Never let a malformed route value reach a uuid-typed query; it is
        // indistinguishable from an unknown workspace and must be a 404.
        if (! Str::isUuid($workspaceId)) {
            return null;
        }

        $currentTenantId = TenantContext::tenantId();

        if ($currentTenantId !== null) {
            $workspace = Workspace::query()->whereKey($workspaceId)->first();

            if ($workspace !== null) {
                return $workspace;
            }
        }

        $tenantIds = Tenant::query()
            ->orderByRaw("case when slug = 'default' then 0 else 1 end")
            ->orderBy('id')
            ->pluck('id');

        foreach ($tenantIds as $tenantId) {
            if ($tenantId === $currentTenantId) {
                continue;
            }

            $workspace = TenantContext::run(
                $tenantId,
                '',
                fn (): ?Workspace => Workspace::query()->whereKey($workspaceId)->first(),
            );

            if ($workspace !== null) {
                return $workspace;
            }
        }

        return null;
    }
}
