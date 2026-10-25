<?php

namespace App\Modules\Retention\Services;

use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Writes and verifies the archive inventory (P4a, RETENTION-MODEL.md §6).
 *
 * Called automatically when a workspace enters `archived`. It counts the rows
 * the account holds per table and hashes a canonical JSON (workspace id +
 * counts). The timestamp is stored next to the hash but is not part of it: a
 * timestamp round-trip through the database must not make an untouched manifest
 * fail verification. The manifest is evidence, not a backup: the encrypted
 * separate store is P4b, but a manifest makes "what was there" a recorded fact
 * instead of a hope.
 *
 * Rules:
 *  - **counts only** - no value, name, note or title is read, so the manifest
 *    cannot leak the data the archive is meant to stop exposing (N-02);
 *  - **soft-deleted rows count** - the archive describes what physically exists;
 *  - **RLS context is mandatory** - the counts are workspace-scoped by policy and
 *    by an explicit `workspace_id` predicate;
 *  - **the hash covers the workspace and the counts**, so editing a count after
 *    the fact fails verification (it is a tamper check for an operator, not a
 *    signature).
 */
final class ArchiveManifestService
{
    /**
     * Tables counted for the inventory. Missing tables (a deployment without
     * the knowledge/AI modules) are skipped rather than reported as zero, so the
     * manifest never claims a table exists when it does not.
     *
     * @var list<string>
     */
    private const TABLES = [
        'health_measurements',
        'health_conditions',
        'allergies',
        'medication_plans',
        'medication_records',
        'appointments',
        'care_tasks',
        'task_events',
        'task_evidence',
        'medical_documents',
        'timeline_entries',
        'notification_deliveries',
        'ai_conversations',
    ];

    public function __construct(private readonly SecurityAuditLogger $audit) {}

    public function createFor(Workspace $workspace, ?CarbonInterface $at = null): WorkspaceArchiveManifest
    {
        $this->assertContext($workspace);

        // Milliseconds are not stored by the model's date format, so the hash
        // must use the same precision that a later verification will read back.
        $at = ($at ?? now())->copy()->utc()->startOfSecond();

        $counts = $this->counts($workspace);

        $manifest = WorkspaceArchiveManifest::query()->create([
            'tenant_id' => (string) $workspace->tenant_id,
            'workspace_id' => (string) $workspace->id,
            'archived_at' => $at,
            'counts' => $counts,
            'sha256' => $this->hash((string) $workspace->id, $counts),
        ]);

        // One audit row; the total is enough to see the size without repeating
        // every table in the audit log.
        $this->audit->record([
            'actor_type' => 'system',
            'action' => 'retention.archive_manifest',
            'target_type' => 'workspace',
            'target_id' => (string) $workspace->id,
            'result' => 'allow',
            'reason_code' => null,
            'metadata' => [
                'tables' => count($counts),
                'rows' => array_sum($counts),
                'sha256' => $manifest->sha256,
            ],
        ]);

        return $manifest;
    }

    /**
     * Recompute the hash from the stored row. False means the counts or the
     * archive instant were changed after the fact.
     */
    public function verify(WorkspaceArchiveManifest $manifest): bool
    {
        $counts = is_array($manifest->counts) ? $manifest->counts : [];

        // jsonb does not preserve key order; the hash was written from a sorted
        // map, so re-sort before hashing or every untouched manifest fails.
        ksort($counts);

        $expected = $this->hash(
            (string) $manifest->workspace_id,
            $counts,
        );

        return hash_equals($manifest->sha256, $expected);
    }

    /**
     * Per-table counts, sorted by table name so the hash is stable.
     *
     * @return array<string, int>
     */
    public function counts(Workspace $workspace): array
    {
        $this->assertContext($workspace);

        $counts = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $counts[$table] = (int) DB::table($table)
                ->where('workspace_id', $workspace->id)
                ->count();
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function hash(string $workspaceId, array $counts): string
    {
        $canonical = [
            'counts' => $counts,
            'workspace_id' => $workspaceId,
        ];

        return hash(
            'sha256',
            json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function assertContext(Workspace $workspace): void
    {
        if (TenantContext::workspaceId() !== $workspace->id) {
            throw new LogicException(
                'Archive manifests require the matching tenant/workspace context (N-01/F-09).'
            );
        }
    }
}
