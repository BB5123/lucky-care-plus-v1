<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Producer for the mutable half of the knowledge corpus (TASK G).
 *
 * Since `fix(deploy): the knowledge corpus is a volume` (#67) the review
 * decisions (`review_status`, `reviewed_by`, `reviewed_at`) live in the
 * `knowledgedata` volume, not in the image. `.github/workflows/backup.yml`
 * dumped PostgreSQL only, so `docker compose down -v` (or a lost volume) would
 * silently revert every answer to the "not yet reviewed" warning. This command
 * is the corpus counterpart of `scripts/backup-dump.sh` and follows the same
 * shape: create, verify the archive is readable, checksum, prune, fail loudly.
 *
 * The archive is a gzipped tar built with PHP's `PharData`, so it needs no
 * external `tar` binary and works on a Windows workstation as well as in CI.
 * The `.sha256` file is written from inside the destination directory, exactly
 * like `backup-dump.sh`, so `sha256sum -c` works after a download.
 *
 * This is NOT a compliance backup: the archive is a plaintext gzipped tar
 * (compression, not encryption), local to the machine that runs it, and
 * retention is best-effort. Off-site storage, encryption at rest and RPO/RTO
 * belong to the deployment target (see backend/docs/BACKUP-COVERAGE.md).
 */
final class KnowledgeBackupCommand extends Command
{
    protected $signature = 'knowledge:backup
        {--to= : Destination directory (default: storage/app/backups/knowledge)}
        {--keep-days=14 : Prune archives older than this many days}
        {--json : Emit a machine-readable summary instead of human text}';

    protected $description = 'Back up the mutable knowledge corpus (sources + SQLite index + benchmark)';

    /** Member name the verification step insists on. */
    private const SQLITE_MEMBER = 'health_kb.sqlite';

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $to = trim((string) $this->option('to'));

        if ($to === '') {
            $to = storage_path('app/backups/knowledge');
        }

        $keepDays = $this->option('keep-days');

        if (! is_string($keepDays) || preg_match('/^\d+$/', $keepDays) !== 1) {
            return $this->abort($json, '--keep-days must be a non-negative integer.');
        }

        $keepDays = (int) $keepDays;

        $sourcesDir = rtrim((string) config('knowledge.sources_path'), '/\\');
        $sqlitePath = (string) config('knowledge.sqlite_path');
        $benchmarkDir = dirname((string) config('knowledge.benchmark_path'));

        if (! is_dir($sourcesDir)) {
            return $this->abort($json, 'The knowledge sources directory does not exist: '.$sourcesDir);
        }

        if (! is_file($sqlitePath)) {
            return $this->abort($json, 'The knowledge SQLite index does not exist: '.$sqlitePath);
        }

        $to = rtrim($to, '/\\');

        if (! is_dir($to) && ! @mkdir($to, 0775, true) && ! is_dir($to)) {
            return $this->abort($json, 'Cannot create the backup directory: '.$to);
        }

        if (! is_writable($to)) {
            return $this->abort($json, 'The backup directory is not writable: '.$to);
        }

        // Resolve to an absolute path before naming the archive: `phar://`
        // member paths are absolute, so a relative `--to` would make the
        // verification prefix below miss every member.
        $to = realpath($to) ?: $to;

        $archive = $this->archivePath($to);
        $checksumFile = $archive.'.sha256';
        $partial = $to.'/'.bin2hex(random_bytes(8)).'.tar.gz';

        try {
            $members = $this->buildArchive($partial, $archive, $sourcesDir, $sqlitePath, $benchmarkDir);
            $verification = $this->verifyArchive($archive);
            $this->writeChecksum($archive, $checksumFile);
            $sha = (string) hash_file('sha256', $archive);
            $pruned = $this->prune($to, $keepDays, $json);
        } catch (Throwable $exception) {
            @unlink($partial);
            @unlink($archive);
            @unlink($checksumFile);

            return $this->abort($json, $exception->getMessage());
        }

        $size = (int) filesize($archive);

        if ($json) {
            $this->line((string) json_encode([
                'archive' => $archive,
                'sha256' => $sha,
                'size_bytes' => $size,
                'members' => $verification['members'],
                'sources' => count($members['sources']),
                'pruned' => $pruned,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf(
                'knowledge backup OK: %s (%d bytes, %d members, sha256 %s)',
                $archive,
                $size,
                $verification['members'],
                $sha,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Pick a free `<to>/knowledge-<UTC timestamp>.tar.gz` name. The timestamp
     * has one-second resolution, so a short random suffix is appended if two
     * backups land in the same second (common in tests).
     */
    private function archivePath(string $to): string
    {
        $timestamp = gmdate('Ymd\THis\Z');

        $candidate = $to.'/knowledge-'.$timestamp.'.tar.gz';

        if (! file_exists($candidate)) {
            return $candidate;
        }

        return $to.'/knowledge-'.$timestamp.'-'.bin2hex(random_bytes(3)).'.tar.gz';
    }

    /**
     * Archive exactly the corpus: `sources/*.json`, the SQLite index and the
     * benchmark directory. The archive is written to a temporary name first and
     * renamed into place, so a failure never leaves a partial backup behind.
     *
     * @return array{sources: list<string>}
     */
    private function buildArchive(
        string $partial,
        string $archive,
        string $sourcesDir,
        string $sqlitePath,
        string $benchmarkDir,
    ): array {
        @unlink($partial);

        $tar = new PharData($partial);
        $sources = [];

        foreach (glob($sourcesDir.'/*.json') ?: [] as $file) {
            $name = basename($file);
            $tar->addFile($file, 'sources/'.$name);
            $sources[] = $name;
        }

        $tar->addFile($sqlitePath, self::SQLITE_MEMBER);

        if (is_dir($benchmarkDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($benchmarkDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($benchmarkDir) + 1));
                $tar->addFile($file->getPathname(), 'benchmark/'.$relative);
            }
        }

        unset($tar);

        if (! @rename($partial, $archive)) {
            @unlink($partial);

            throw new RuntimeException('Cannot move the archive into place: '.$archive);
        }

        return ['sources' => $sources];
    }

    /**
     * Open the archive back and fail if it is empty or the two things every
     * corpus backup must carry are missing. An archive that cannot be listed is
     * not a backup.
     *
     * @return array{members: int}
     */
    private function verifyArchive(string $archive): array
    {
        $members = $this->members($archive);

        if ($members === []) {
            throw new RuntimeException('The archive is empty.');
        }

        $hasSources = false;

        foreach ($members as $member) {
            if (str_starts_with($member, 'sources/') && str_ends_with($member, '.json')) {
                $hasSources = true;
                break;
            }
        }

        if (! $hasSources) {
            throw new RuntimeException('The archive does not contain any sources/ entry.');
        }

        if (! in_array(self::SQLITE_MEMBER, $members, true)) {
            throw new RuntimeException('The archive does not contain '.self::SQLITE_MEMBER.'.');
        }

        return ['members' => count($members)];
    }

    /**
     * @return list<string>
     */
    private function members(string $archive): array
    {
        $archive = realpath($archive) ?: $archive;
        $prefix = 'phar://'.str_replace('\\', '/', $archive).'/';
        $members = [];

        foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
            $pathname = str_replace('\\', '/', $file->getPathname());

            if (str_starts_with($pathname, $prefix)) {
                $members[] = substr($pathname, strlen($prefix));
            } else {
                $members[] = $file->getFilename();
            }
        }

        sort($members);

        return $members;
    }

    private function writeChecksum(string $archive, string $checksumFile): void
    {
        $sha = hash_file('sha256', $archive);

        if ($sha === false) {
            throw new RuntimeException('Cannot checksum the archive: '.$archive);
        }

        // Written from inside the destination directory so the file carries only
        // the basename, like `sha256sum` does, and `sha256sum -c` works next to
        // a downloaded archive.
        $written = file_put_contents($checksumFile, $sha.'  '.basename($archive)."\n");

        if ($written === false) {
            throw new RuntimeException('Cannot write the checksum file: '.$checksumFile);
        }
    }

    /**
     * Delete `knowledge-*.tar.gz` (+ `.sha256`) older than $keepDays inside $to,
     * refusing to leave zero archives behind. Mirrors backup-dump.sh.
     */
    private function prune(string $to, int $keepDays, bool $json): int
    {
        $existing = glob($to.'/knowledge-*.tar.gz') ?: [];
        $cutoff = time() - ($keepDays * 86400);
        $candidates = [];

        foreach ($existing as $file) {
            $mtime = @filemtime($file);

            if ($mtime !== false && $mtime <= $cutoff) {
                $candidates[] = $file;
            }
        }

        if ($candidates === []) {
            return 0;
        }

        if (count($existing) - count($candidates) <= 0) {
            if (! $json) {
                $this->warn('refusing to prune: it would leave zero knowledge archives');
            }

            return 0;
        }

        $pruned = 0;

        foreach ($candidates as $file) {
            // Belt and braces: glob already fixes the directory, but deleting
            // the wrong file during a backup job is not worth risking.
            if (dirname($file) !== $to) {
                continue;
            }

            if (@unlink($file)) {
                @unlink($file.'.sha256');
                $pruned++;
            }
        }

        return $pruned;
    }

    private function abort(bool $json, string $message): int
    {
        if ($json) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => $message],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error('knowledge:backup failed: '.$message);
        }

        return self::FAILURE;
    }
}
