<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads a user's memberships across workspaces.
 *
 * There is deliberately no global "list every membership" query: both
 * `workspaces` and `workspace_memberships` are protected by RLS, and an
 * unauthenticated-context request has no tenant. The trusted directory
 * (`tenants`) is walked instead, then each tenant's workspaces, and the
 * membership is read under that exact tenant/workspace context. This is the
 * same bootstrap rule as `WorkspaceResolver`; it only reads rows that belong
 * to the authenticated user.
 */
class MembershipDirectory
{
    /**
     * @return Collection<int, array{workspace: Workspace, membership: WorkspaceMembership}>
     */
    public function forUser(User $user): Collection
    {
        $entries = collect();

        $tenantIds = Tenant::query()
            ->orderByRaw("case when slug = 'default' then 0 else 1 end")
            ->orderBy('id')
            ->pluck('id');

        foreach ($tenantIds as $tenantId) {
            DB::transaction(function () use ($tenantId, $user, $entries): void {
                TenantContext::set($tenantId, '');

                try {
                    /** @var Collection<int, Workspace> $workspaces */
                    $workspaces = Workspace::query()->orderBy('name')->get();

                    foreach ($workspaces as $workspace) {
                        TenantContext::set($tenantId, $workspace->id);

                        $membership = WorkspaceMembership::query()
                            ->where('workspace_id', $workspace->id)
                            ->where('user_id', $user->id)
                            ->first();

                        if ($membership instanceof WorkspaceMembership) {
                            $entries->push([
                                'workspace' => $workspace,
                                'membership' => $membership,
                            ]);
                        }
                    }
                } finally {
                    TenantContext::clear();
                }
            });
        }

        return $entries;
    }
}
