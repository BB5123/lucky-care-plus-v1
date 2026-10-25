<?php

namespace App\Modules\Retention\Services;

use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Retention\Domain\ArchiveTables;
use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/**
 * The encrypted archive bundle (P4b-1).
 *
 * When a workspace is archived, its rows are written as NDJSON (one row per
 * line, parents before children so a restore can insert in order), gzipped and
 * encrypted with the application key. The ciphertext lives on the archive disk,
 * outside the active database tables; the manifest records the path, the
 * ciphertext's sha256 and its size.
 *
 * What this is: an encrypted copy of the account's content that can be verified
 * without the database. What this is not yet (P4b-2): a restore path that
 * re-inserts the rows, per-archive encryption keys, and deletion semantics for
 * the archive. Until P4b-2, the final `scheduled_deletion -> deleted` arrow
 * stays closed - the copy exists, but nothing claims to restore from it.
 *
 * Memory note: the NDJSON is built per table and gzipped as a whole. A family
 * account is small (hundreds to thousands of rows); P4b-2 should stream for
 * larger accounts, which is why the format is NDJSON and not one JSON document.
 */
final class ArchiveBundleService
{
    public const FORMAT = 1;

    public const AUDIT_ACTION = 'retention.archive_bundle';

    public function __construct(private readonly SecurityAuditLogger $audit) {}

    public function write(Workspace $workspace, WorkspaceArchiveManifest $manifest, ?CarbonInterface $at = null): WorkspaceArchiveManifest
    {
        $this->assertContext($workspace);

        $at = ($at ?? now())->copy()->utc()->startOfSecond();

        $ndjson = $this->snapshot($workspace, $at);

        // The archive file name carries ids only: no profile name, no document
        // name, no date of care (N-02).
        $path = sprintf(
            'archives/%s/%s/manifest-%s.json.gz.enc',
            $workspace->tenant_id,
            $workspace->id,
            $manifest->id,
        );

        $cipher = Crypt::encryptString(gzencode($ndjson, 6));

        $disk = $this->disk();
        $disk->put($path, $cipher);

        $manifest->forceFill([
            'archive_path' => $path,
            'archive_sha256' => hash('sha256', $cipher),
            'archive_bytes' => strlen($cipher),
        ])->save();

        $this->audit->record([
            'actor_type' => 'system',
            'action' => self::AUDIT_ACTION,
            'target_type' => 'workspace',
            'target_id' => (string) $workspace->id,
            'result' => 'allow',
            'reason_code' => null,
            'metadata' => [
                'bytes' => strlen($cipher),
                'sha256' => $manifest->archive_sha256,
            ],
        ]);

        return $manifest;
    }

    /**
     * Verify the bundle against its manifest: file present, ciphertext hash
     * unchanged, decryptable, and the row count per table equal to the inventory.
     *
     * @return array{ok: bool, counts: array<string, int>, problems: list<string>, bytes: int}
     */
    public function verify(WorkspaceArchiveManifest $manifest): array
    {
        $counts = [];
        $problems = [];

        if ($manifest->archive_path === null || $manifest->archive_path === '') {
            return [
                'ok' => false,
                'counts' => [],
                'problems' => ['no archive bundle was recorded for this manifest (P4a only)'],
                'bytes' => 0,
            ];
        }

        $disk = $this->disk();

        if (! $disk->exists($manifest->archive_path)) {
            return [
                'ok' => false,
                'counts' => [],
                'problems' => ['the archive bundle file is missing'],
                'bytes' => 0,
            ];
        }

        $cipher = (string) $disk->get($manifest->archive_path);

        if (! hash_equals((string) $manifest->archive_sha256, hash('sha256', $cipher))) {
            $problems[] = 'the archive bundle hash does not match the manifest';
        }

        try {
            $ndjson = gzdecode(Crypt::decryptString($cipher));
        } catch (Throwable) {
            $problems[] = 'the archive bundle cannot be decrypted with the current APP_KEY';

            return ['ok' => false, 'counts' => [], 'problems' => $problems, 'bytes' => strlen($cipher)];
        }

        if (! is_string($ndjson)) {
            $problems[] = 'the archive bundle cannot be decompressed';

            return ['ok' => false, 'counts' => [], 'problems' => $problems, 'bytes' => strlen($cipher)];
        }

        $lines = preg_split('/\R/', trim($ndjson)) ?: [];
        $header = json_decode((string) array_shift($lines), true);

        if (! is_array($header) || ($header['format'] ?? null) !== self::FORMAT) {
            $problems[] = 'the archive bundle header is not recognisable';
        } elseif (($header['workspace_id'] ?? null) !== (string) $manifest->workspace_id) {
            $problems[] = 'the archive bundle belongs to another workspace';
        }

        $known = array_flip(ArchiveTables::ALL);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (! is_array($row) || ! isset($row['table'])) {
                $problems[] = 'the archive bundle contains a line that is not a row';

                continue;
            }

            $table = (string) $row['table'];

            if (! isset($known[$table])) {
                $problems[] = "the archive bundle contains an unknown table: {$table}";

                continue;
            }

            $counts[$table] = ($counts[$table] ?? 0) + 1;
        }

        $inventory = is_array($manifest->counts) ? $manifest->counts : [];

        foreach (ArchiveTables::ALL as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $expected = (int) ($inventory[$table] ?? 0);
            $actual = (int) ($counts[$table] ?? 0);

            if ($expected !== $actual) {
                $problems[] = "row count differs for {$table}: inventory {$expected}, bundle {$actual}";
            }
        }

        return [
            'ok' => $problems === [],
            'counts' => $counts,
            'problems' => $problems,
            'bytes' => strlen($cipher),
        ];
    }

    private function snapshot(Workspace $workspace, CarbonInterface $at): string
    {
        $lines = [
            json_encode([
                'format' => self::FORMAT,
                'workspace_id' => (string) $workspace->id,
                'archived_at' => $at->format('Y-m-d\TH:i:s\Z'),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        foreach (ArchiveTables::ALL as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $lines[] = json_encode([
                    'table' => $table,
                    'row' => (array) $row,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('retention.archive_disk', 'local'));
    }

    private function assertContext(Workspace $workspace): void
    {
        if (TenantContext::workspaceId() !== $workspace->id) {
            throw new LogicException(
                'Archive bundles require the matching tenant/workspace context (N-01/F-09).'
            );
        }
    }
}
