<?php

namespace App\Console\Commands;

use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Archives the encrypted retention bundles (gap 4: the archive disk).
 *
 * The nightly database dump does not contain the bundles - they live on
 * `RETENTION_ARCHIVE_DISK` - so a restored database would name archived accounts
 * whose bundles are gone. This command closes that hole for the local disk:
 *
 *  1. read every manifest with a bundle (inside its workspace's RLS context);
 *  2. check the file exists and its sha256 still matches the manifest;
 *  3. zip the bundles (ids only in the entry names) with a `manifest.json`
 *     that carries ids, sizes, hashes and key algorithm - never content;
 *  4. write `<archive>.sha256`;
 *  5. fail when a bundle is missing or does not match (the archive is still
 *     written, so the operator can see what is missing).
 *
 * `retention:archive-drill-backup <archive>` re-reads the zip and verifies it.
 * S3/MinIO is refused rather than silently archiving nothing: mirror the bucket
 * and run the drill on the archive that produces.
 */
final class RetentionArchiveBackupCommand extends Command
{
    protected $signature = 'retention:archive-backup
        {--output= : Archive path (default: storage/app/backups/archives-<UTC>.zip)}
        {--json : Machine-readable summary}';

    protected $description = 'Archive the encrypted retention bundles and verify each against its manifest';

    public function handle(): int
    {
        $diskName = (string) config('retention.archive_disk', 'local');
        $driver = (string) config("filesystems.disks.{$diskName}.driver", '');

        if ($driver !== 'local') {
            $this->error(
                "retention:archive-backup supports the local disk only (driver: {$driver}). "
                .'For s3/MinIO mirror the bucket and verify the archive with retention:archive-drill-backup.'
            );

            return self::FAILURE;
        }

        try {
            $summary = $this->archive($diskName, (string) ($this->option('output') ?: ''));
        } catch (Throwable $exception) {
            $this->error('Archive backup failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Archive', 'Bundles', 'Missing', 'Mismatched', 'Bytes'],
                [[
                    basename($summary['archive']),
                    $summary['bundles'],
                    count($summary['missing']),
                    count($summary['mismatched']),
                    $summary['bytes'],
                ]],
            );

            $this->info("Archive: {$summary['archive']} (sha256 {$summary['sha256']})");
        }

        if ($summary['missing'] !== [] || $summary['mismatched'] !== []) {
            $this->error(
                count($summary['missing']).' bundle(s) are missing and '
                .count($summary['mismatched']).' do not match their manifest; the archive is incomplete.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     archive: string,
     *     sha256: string,
     *     bundles: int,
     *     bytes: int,
     *     missing: list<string>,
     *     mismatched: list<string>
     * }
     */
    private function archive(string $diskName, string $requestedOutput): array
    {
        $disk = Storage::disk($diskName);

        $output = $requestedOutput !== ''
            ? $requestedOutput
            : storage_path('app/backups/archives-'.now()->utc()->format('Ymd\THis\Z').'.zip');

        $directory = dirname($output);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the archive directory.');
        }

        $manifests = $this->manifests();
        $entries = [];
        $missing = [];
        $mismatched = [];
        $bytes = 0;

        $zip = new ZipArchive;

        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the archive for writing.');
        }

        try {
            foreach ($manifests as $manifest) {
                $path = (string) $manifest->archive_path;

                if (! $disk->exists($path)) {
                    $missing[] = (string) $manifest->id;

                    continue;
                }

                $absolute = $disk->path($path);
                $sha256 = (string) hash_file('sha256', $absolute);

                if (! hash_equals((string) $manifest->archive_sha256, $sha256)) {
                    $mismatched[] = (string) $manifest->id;
                }

                $size = (int) filesize($absolute);
                $bytes += $size;
                $entry = 'archives/'.$manifest->id.'.enc';

                $zip->addFile($absolute, $entry);

                $entries[] = [
                    'manifest_id' => (string) $manifest->id,
                    'workspace_id' => (string) $manifest->workspace_id,
                    'archived_at' => $manifest->archived_at?->toIso8601String(),
                    'bytes' => $size,
                    'sha256' => $sha256,
                    'key_algorithm' => $manifest->key_algorithm,
                    'entry' => $entry,
                ];
            }

            $zip->addFromString(
                'manifest.json',
                json_encode([
                    'created_at' => now()->toIso8601String(),
                    'disk' => $diskName,
                    'bundles' => $entries,
                    'missing' => $missing,
                    'mismatched' => $mismatched,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
        } finally {
            $zip->close();
        }

        $sha256 = (string) hash_file('sha256', $output);
        file_put_contents($output.'.sha256', $sha256.'  '.basename($output).PHP_EOL);

        return [
            'archive' => $output,
            'sha256' => $sha256,
            'bundles' => count($entries),
            'bytes' => $bytes,
            'missing' => $missing,
            'mismatched' => $mismatched,
        ];
    }

    /**
     * Every manifest that points at a bundle, read inside its tenant/workspace
     * context because both tables are RLS-forced.
     *
     * @return list<WorkspaceArchiveManifest>
     */
    private function manifests(): array
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
}
