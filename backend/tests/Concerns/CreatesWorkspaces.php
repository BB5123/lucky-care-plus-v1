<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Support\Str;

/**
 * Builds tenant-scoped fixtures inside the test's outer transaction.
 *
 * `set_config(..., true)` is transaction-local, so setting the context here is
 * enough: `RefreshDatabase` wraps every test in one transaction and all
 * subsequent fixture inserts are checked by RLS under that context.
 */
trait CreatesWorkspaces
{
    protected function createTenant(string $slug = 'test-tenant'): Tenant
    {
        return Tenant::query()->forceCreate([
            'id' => Uuid7::generate(),
            'name' => 'Test Tenant',
            'slug' => $slug,
        ]);
    }

    protected function createWorkspace(Tenant $tenant, string $name = 'Test Workspace'): Workspace
    {
        $workspaceId = Uuid7::generate();

        TenantContext::set($tenant->id, $workspaceId);

        return Workspace::query()->forceCreate([
            'id' => $workspaceId,
            'tenant_id' => $tenant->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.substr($workspaceId, 0, 8),
            'settings' => [],
            'break_glass_enabled' => false,
        ]);
    }

    protected function addMember(Workspace $workspace, User $user, string $role): WorkspaceMembership
    {
        TenantContext::set($workspace->tenant_id, $workspace->id);

        return WorkspaceMembership::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
