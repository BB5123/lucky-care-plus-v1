<?php

namespace App\Console\Commands;

use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Retention\Services\ArchiveAccessAuditor;
use App\Modules\Retention\Services\ArchiveManifestService;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceResolver;
use App\Support\Database\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prints the latest archive inventory for a workspace (P4a).
 *
 * Support and restore work need "what did the account hold when it was
 * archived?" without loading the data. The manifest answers that with counts and
 * a hash; this command shows it and re-verifies the hash, so an edited manifest
 * is visible instead of trusted.
 */
final class RetentionArchiveManifestCommand extends Command
{
    protected $signature = 'retention:archive-manifest
        {workspace : Workspace UUID}
        {--json : Machine-readable output}';

    protected $description = 'Show the latest archive inventory (counts + hash) for a workspace';

    public function handle(
        WorkspaceResolver $resolver,
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
                function () use ($workspace, $auditor): ?WorkspaceArchiveManifest {
                    $manifest = WorkspaceArchiveManifest::query()
                        ->where('workspace_id', $workspace->id)
                        ->orderByDesc('archived_at')
                        ->orderByDesc('id')
                        ->first();

                    if ($manifest instanceof WorkspaceArchiveManifest) {
                        $auditor->read($workspace, 'archive-manifest');
                    }

                    return $manifest;
                },
            );
        } catch (Throwable $exception) {
            $this->error('Could not read the archive manifest: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        if (! $manifest instanceof WorkspaceArchiveManifest) {
            $this->error('No archive manifest exists for this workspace (it has never entered `archived`).');

            return self::FAILURE;
        }

        $counts = is_array($manifest->counts) ? $manifest->counts : [];
        $verified = $manifests->verify($manifest);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'workspace_id' => $workspace->id,
                'archived_at' => $manifest->archived_at?->toIso8601String(),
                'counts' => $counts,
                'rows' => array_sum($counts),
                'sha256' => $manifest->sha256,
                'verified' => $verified,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Table', 'Rows'],
            array_map(static fn (string $table, int $count): array => [$table, $count], array_keys($counts), $counts),
        );

        $this->line('Archived at: '.($manifest->archived_at?->toIso8601String() ?? '-'));
        $this->line('Total rows: '.array_sum($counts));
        $this->line('sha256: '.$manifest->sha256);
        $this->line('Hash verifies: '.($verified ? 'yes' : 'NO - the manifest was modified'));

        return $verified ? self::SUCCESS : self::FAILURE;
    }
}
