<?php

namespace App\Modules\Retention\Services;

use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Reads every archive manifest that points at a bundle (P4b).
 *
 * Both the bundle backup and its drill need the same list, and both must read it
 * inside each workspace's RLS context: a plain `WorkspaceArchiveManifest::all()`
 * in a console command returns nothing, which would make a broken backup look
 * complete. One place, one query shape, one context rule.
 */
final class ArchiveBundleRegistry
{
    /**
     * @return list<WorkspaceArchiveManifest>
     */
    public function all(): array
    {
        $manifests = [];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            $tenantId = (string) $tenantId;

            DB::transaction(function () use ($tenantId, &$manifests): void {
                TenantContext::run($tenantId, '', function () use ($tenantId, &$manifests): void {
                    $workspaceIds = Workspace::query()->where('tenant_id', $tenantId)->orderBy('id')->pluck('id');

                    foreach ($workspaceIds as $workspaceId) {
                        TenantContext::run($tenantId, (string) $workspaceId, function () use ($workspaceId, &$manifests): void {
                            $rows = WorkspaceArchiveManifest::query()
                                ->where('workspace_id', $workspaceId)
                                ->whereNotNull('archive_path')
                                ->orderBy('archived_at')
                                ->orderBy('id')
                                ->get();

                            foreach ($rows as $row) {
                                $manifests[] = $row;
                            }
                        });
                    }
                });
            });
        }

        return $manifests;
    }

    /**
     * @return array<string, WorkspaceArchiveManifest> keyed by manifest id
     */
    public function byId(): array
    {
        $byId = [];

        foreach ($this->all() as $manifest) {
            $byId[(string) $manifest->id] = $manifest;
        }

        return $byId;
    }
}
