<?php

namespace App\Console\Commands;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Archives the private document objects (gap 4 in BACKUP-COVERAGE.md).
 *
 * The nightly workflow covers the database and the knowledge corpus; the
 * uploads on the `appstorage` volume had no producer at all. This command is
 * that producer for the reference stack (local disk):
 *
 *  1. collect the object keys the database expects - `documents/{tenant}/{id}`
 *     is deterministic, so every `medical_documents` row must have a file;
 *  2. zip the files under their storage keys and put a manifest inside;
 *  3. write `<archive>.sha256`;
 *  4. fail when a row has no object (the archive is still written, so the
 *     operator can inspect what is missing) and warn about orphan objects.
 *
 * `documents:restore-drill <archive>` re-reads the archive and verifies every
 * file against the manifest.
 *
 * S3/MinIO is deliberately not streamed through Flysystem here: a deployment
 * using `DOCUMENTS_DISK=s3` should mirror the bucket with `mc mirror` / `aws s3
 * sync`, then run the drill on whatever archive it produced. Pretending to
 * support both would make the command silently back up nothing on one of them.
 */
final class DocumentsBackupCommand extends Command
{
    protected $signature = 'documents:backup
        {--output= : Archive path (default: storage/app/backups/documents-<UTC>.zip)}
        {--keep-days=14 : Prune document archives older than this many days}
        {--json : Machine-readable summary}';

    protected $description = 'Archive the private document objects and verify every metadata row has its object';

    public function handle(): int
    {
        $diskName = (string) config('documents.disk', 'local');
        $driver = (string) config("filesystems.disks.{$diskName}.driver", '');

        if ($driver !== 'local') {
            $this->error(
                "documents:backup supports the local disk only (driver: {$driver}). "
                .'For s3/MinIO mirror the bucket (mc mirror / aws s3 sync) and verify the archive '
                .'with documents:restore-drill.'
            );

            return self::FAILURE;
        }

        $keepDays = filter_var(
            $this->option('keep-days'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($keepDays === false) {
            $this->error('The --keep-days option must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $summary = $this->archive($diskName, (string) ($this->option('output') ?: ''), $keepDays);
        } catch (Throwable $exception) {
            $this->error('Document backup failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Archive', 'Files', 'Rows', 'Missing', 'Orphans', 'Bytes'],
                [[
                    basename($summary['archive']),
                    $summary['files'],
                    $summary['rows'],
                    count($summary['missing']),
                    count($summary['orphans']),
                    $summary['bytes'],
                ]],
            );

            $this->info("Archive: {$summary['archive']} (sha256 {$summary['sha256']})");

            if ($summary['orphans'] !== []) {
                $this->warn(
                    count($summary['orphans']).' object(s) have no metadata row; they are in the archive but '
                    .'are not referenced by the database.'
                );
            }
        }

        if ($summary['missing'] !== []) {
            // The archive is still written: it is the evidence of what exists
            // and what does not. The exit code is what fails the nightly run.
            $this->error(
                count($summary['missing']).' document row(s) have no object; the archive is incomplete.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     archive: string,
     *     sha256: string,
     *     files: int,
     *     rows: int,
     *     bytes: int,
     *     missing: list<string>,
     *     orphans: list<string>
     * }
     */
    private function archive(string $diskName, string $requestedOutput, int $keepDays): array
    {
        $disk = Storage::disk($diskName);
        $root = $disk->path('documents');

        $output = $requestedOutput !== ''
            ? $requestedOutput
            : storage_path('app/backups/documents-'.now()->utc()->format('Ymd\THis\Z').'.zip');

        $directory = dirname($output);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the archive directory.');
        }

        $expected = $this->expectedKeys();
        $files = $this->filesUnder($root);

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'disk' => $diskName,
            'files' => [],
            'rows' => count($expected),
        ];

        $zip = new ZipArchive;

        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the archive for writing.');
        }

        $bytes = 0;

        try {
            foreach ($files as $key => $absolute) {
                $size = (int) filesize($absolute);
                $bytes += $size;

                $zip->addFile($absolute, $key);
                $manifest['files'][] = [
                    'path' => $key,
                    'size' => $size,
                    'sha256' => (string) hash_file('sha256', $absolute),
                ];
            }

            $missing = array_values(array_diff(array_keys($expected), array_keys($files)));
            $orphans = array_values(array_diff(array_keys($files), array_keys($expected)));

            $manifest['missing'] = $missing;
            $manifest['orphans'] = $orphans;

            $zip->addFromString(
                'manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
        } finally {
            $zip->close();
        }

        $sha256 = (string) hash_file('sha256', $output);
        file_put_contents($output.'.sha256', $sha256.'  '.basename($output).PHP_EOL);

        $this->prune($directory, $output, $keepDays);

        return [
            'archive' => $output,
            'sha256' => $sha256,
            'files' => count($files),
            'rows' => count($expected),
            'bytes' => $bytes,
            'missing' => $missing,
            'orphans' => $orphans,
        ];
    }

    /**
     * The object keys the database expects, as `documents/{tenant}/{document}`.
     *
     * The path is deterministic (DocumentService), so this cross-check needs no
     * decryption of `storage_path`. Rows are read inside each workspace's
     * tenant context because `medical_documents` is RLS-forced.
     *
     * @return array<string, true>
     */
    private function expectedKeys(): array
    {
        $keys = [];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            $tenantId = (string) $tenantId;

            DB::transaction(function () use ($tenantId, &$keys): void {
                TenantContext::run($tenantId, '', function () use ($tenantId, &$keys): void {
                    $workspaceIds = Workspace::query()->where('tenant_id', $tenantId)->orderBy('id')->pluck('id');

                    foreach ($workspaceIds as $workspaceId) {
                        TenantContext::run($tenantId, (string) $workspaceId, function () use ($tenantId, $workspaceId, &$keys): void {
                            $ids = DB::table('medical_documents')
                                ->where('workspace_id', $workspaceId)
                                ->pluck('id');

                            foreach ($ids as $id) {
                                $keys['documents/'.$tenantId.'/'.$id] = true;
                            }
                        });
                    }
                });
            });
        }

        return $keys;
    }

    /**
     * @return array<string, string> storage key => absolute path
     */
    private function filesUnder(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($absolute, strlen(str_replace('\\', '/', $root))), '/');

            $files['documents/'.$relative] = $absolute;
        }

        ksort($files);

        return $files;
    }

    private function prune(string $directory, string $current, int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $candidates = glob($directory.'/documents-*.zip') ?: [];

        // Keep the newest archive even when it is older than the window, so a
        // clock jump or a long outage cannot delete the only copy.
        usort($candidates, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $newest = $candidates[0] ?? null;

        foreach ($candidates as $path) {
            if ($path === $current || $path === $newest || ! is_file($path)) {
                continue;
            }

            if (filemtime($path) < $cutoff) {
                @unlink($path);
                @unlink($path.'.sha256');
            }
        }
    }
}
