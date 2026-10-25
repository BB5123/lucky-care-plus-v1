<?php

namespace App\Console\Commands;

use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Retention\Services\ArchiveAccessAuditor;
use App\Modules\Retention\Services\ArchiveBundleService;
use App\Modules\Retention\Services\ArchiveManifestService;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceResolver;
use App\Support\Database\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verifies the encrypted archive bundle against the inventory (P4b-1).
 *
 * The drill answers three questions without a restore: is the file there, is
 * its ciphertext the one the manifest recorded, and does it hold the row counts
 * the inventory promises? A backup that cannot be verified is only a hope.
 */
final class RetentionArchiveDrillCommand extends Command
{
    protected $signature = 'retention:archive-drill
        {workspace : Workspace UUID}
        {--json : Machine-readable output}';

    protected $description = 'Verify an archived workspace bundle (file, hash, decryptability, counts)';

    public function handle(
        WorkspaceResolver $resolver,
        ArchiveBundleService $bundles,
        ArchiveManifestService $manifests,
        ArchiveAccessAuditor $auditor,
    ): int {
        $workspace = $resolver->resolve((string) $this->argument('workspace'));

        if (! $workspace instanceof Workspace) {
            $this->error('Unknown workspace.');

            return self::FAILURE;
        }

        try {
            $manifest = TenantContext::run(
                $workspace->tenant_id,
                $workspace->id,
                fn (): ?WorkspaceArchiveManifest => WorkspaceArchiveManifest::query()
                    ->where('workspace_id', $workspace->id)
                    ->orderByDesc('archived_at')
                    ->orderByDesc('id')
                    ->first(),
            );
        } catch (Throwable $exception) {
            $this->error('Could not read the archive manifest: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        if (! $manifest instanceof WorkspaceArchiveManifest) {
            $this->error('No archive manifest exists for this workspace.');

            return self::FAILURE;
        }

        $result = $bundles->verify($manifest);
        $inventoryVerified = $manifests->verify($manifest);
        $ok = $result['ok'] && $inventoryVerified;

        TenantContext::run(
            $workspace->tenant_id,
            $workspace->id,
            fn (): mixed => $auditor->read($workspace, 'archive-drill', $ok),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'workspace_id' => $workspace->id,
                'archive_path' => $manifest->archive_path,
                'archive_bytes' => $result['bytes'],
                'counts' => $result['counts'],
                'bundle_verified' => $result['ok'],
                'inventory_verified' => $inventoryVerified,
                'problems' => $result['problems'],
            ], JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Table', 'Rows in bundle'],
            array_map(
                static fn (string $table, int $count): array => [$table, $count],
                array_keys($result['counts']),
                $result['counts'],
            ),
        );

        $this->line('Archive: '.($manifest->archive_path ?? '-'));
        $this->line('Bytes: '.$result['bytes']);
        $this->line('Bundle verifies: '.($result['ok'] ? 'yes' : 'NO'));
        $this->line('Inventory hash verifies: '.($inventoryVerified ? 'yes' : 'NO'));

        foreach ($result['problems'] as $problem) {
            $this->error($problem);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
