<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a workspace inside the default tenant and makes the creator its
 * `workspace_admin`.
 *
 * The tenant is chosen server-side (default tenant); a client can never supply
 * `tenant_id` or its own `role` (N-01, AP-002). The workspace id is generated
 * before the insert so the RLS context and the membership row agree.
 */
class WorkspaceService
{
    /**
     * @return array{workspace: Workspace, membership: WorkspaceMembership}
     */
    public function createFor(User $actor, string $name): array
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Lucky Care Plus'],
        );

        $workspaceId = Uuid7::generate();

        return DB::transaction(function () use ($tenant, $workspaceId, $actor, $name): array {
            TenantContext::set($tenant->id, $workspaceId);

            try {
                $workspace = Workspace::query()->forceCreate([
                    'id' => $workspaceId,
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'slug' => $this->uniqueSlug($tenant->id, $name),
                    'settings' => [],
                    'break_glass_enabled' => false,
                ]);

                $membership = WorkspaceMembership::query()->forceCreate([
                    'id' => Uuid7::generate(),
                    'tenant_id' => $tenant->id,
                    'workspace_id' => $workspace->id,
                    'user_id' => $actor->id,
                    'role' => 'workspace_admin',
                    'status' => 'active',
                ]);

                return ['workspace' => $workspace, 'membership' => $membership];
            } finally {
                TenantContext::clear();
            }
        });
    }

    private function uniqueSlug(string $tenantId, string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'workspace';
        }

        $slug = $base;
        $suffix = 2;

        while (Workspace::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
