<?php

namespace App\Modules\Retention\Services;

use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;

/**
 * Deletes data that has passed its retention period (ADR-016 §5).
 *
 * Safety rules enforced in code, not only in prose:
 *   - audit tables are a hard-coded forbidden target (DM-R05);
 *   - an active legal hold on the workspace or the row's profile skips the
 *     row (DM-R04);
 *   - health data is soft-deleted when its model supports `SoftDeletes`
 *     (DM-R01), other tables are hard-deleted;
 *   - exactly one `security_audit_logs` row is written per batch, carrying
 *     counts only (never row contents, N-02);
 *   - `retention.dry_run = true` deletes nothing but still reports and audits
 *     the would-be batch;
 *   - already purged rows are invisible to the next run, so a second batch is
 *     a safe no-op (DM-R07);
 *   - `compactBatch()` is the second half: it physically removes rows that
 *     have been soft-deleted past the grace window (P1), so "deleted" in the
 *     privacy notice is a fact and not a euphemism.
 */
final class PurgeService
{
    /**
     * Types/tables that must never be touched by retention, whatever the
     * caller says.
     *
     * @var list<string>
     */
    private const FORBIDDEN_TYPES = [
        'audit_events',
        'security_audit_logs',
        'audit_months',
    ];

    /**
     * Types that map to a concrete business table.
     *
     * `date_column` is the clock from ADR-016 §1. Types without an entry are
     * recognised but reported as "not yet implemented" (documents_identity
     * needs the delegation end join; notification periods are not rows in a
     * table owned by this service).
     *
     * `objects` marks a table whose rows point at a private object. The object
     * is deleted before the row, so a failed object delete never leaves a row
     * that claims an object exists (or an orphan object with no metadata).
     *
     * @var array<string, array{table: string, date_column: string, model: class-string|null, soft_deletes: bool, objects?: bool}>
     */
    private const TYPE_SPECS = [
        'health_measurements' => [
            'table' => 'health_measurements',
            'date_column' => 'recorded_at',
            'model' => HealthMeasurement::class,
            'soft_deletes' => true,
        ],
        'health_conditions' => [
            'table' => 'health_conditions',
            'date_column' => 'updated_at',
            'model' => null,
            'soft_deletes' => false,
        ],
        'allergies' => [
            'table' => 'allergies',
            'date_column' => 'updated_at',
            'model' => null,
            'soft_deletes' => false,
        ],
        'medication_plans' => [
            'table' => 'medication_plans',
            'date_column' => 'updated_at',
            'model' => null,
            'soft_deletes' => false,
        ],
        'medication_records' => [
            'table' => 'medication_records',
            'date_column' => 'updated_at',
            'model' => null,
            'soft_deletes' => false,
        ],
        'appointments' => [
            'table' => 'appointments',
            'date_column' => 'scheduled_at',
            'model' => null,
            'soft_deletes' => false,
        ],
        'care_tasks' => [
            'table' => 'care_tasks',
            'date_column' => 'created_at',
            'model' => null,
            'soft_deletes' => false,
        ],
        'task_events' => [
            'table' => 'task_events',
            'date_column' => 'created_at',
            'model' => null,
            'soft_deletes' => false,
        ],
        'task_evidence' => [
            'table' => 'task_evidence',
            'date_column' => 'created_at',
            'model' => null,
            'soft_deletes' => false,
        ],
        'documents_medical' => [
            'table' => 'medical_documents',
            'date_column' => 'created_at',
            'model' => null,
            'soft_deletes' => false,
            'objects' => true,
        ],
        'medical_documents' => [
            'table' => 'medical_documents',
            'date_column' => 'created_at',
            'model' => null,
            'soft_deletes' => false,
            'objects' => true,
        ],
        'ai_conversations' => [
            'table' => 'ai_conversations',
            'date_column' => 'created_at',
            'model' => null,
            'soft_deletes' => false,
        ],
    ];

    public function __construct(
        private readonly RetentionPolicy $policy,
        private readonly LegalHold $holds,
        private readonly SecurityAuditLogger $audit,
    ) {}

    /**
     * @return list<string>
     */
    public function purgeableTypes(): array
    {
        return array_keys(self::TYPE_SPECS);
    }

    /**
     * Run one pump of the purge job for the active tenant/workspace context.
     *
     * The context is mandatory: the job never guesses a tenant from a row, and
     * RLS would hide every row anyway (fail closed, F-09).
     */
    public function purgeBatch(string $type, int $limit = 500): PurgeReport
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Purge batch size must be at least 1.');
        }

        $this->assertTypeMayBePurged($type);

        $dryRun = (bool) config('retention.dry_run', true);

        $context = $this->activeContext($type);
        $tenant = $context['tenant'];
        $workspaceId = $context['workspace_id'];

        $spec = self::TYPE_SPECS[$type] ?? null;

        if ($spec === null) {
            return PurgeReport::skipped($type, 'not_yet_implemented', $dryRun);
        }

        if (! Schema::hasTable($spec['table'])) {
            return PurgeReport::skipped($type, 'table_not_present', $dryRun);
        }

        $months = $this->policy->monthsFor($type, $tenant);

        if ($months === null) {
            return PurgeReport::skipped($type, 'retained_indefinitely', $dryRun);
        }

        $cutoff = now()->subMonths($months);

        $base = DB::table($spec['table'])->where($spec['date_column'], '<', $cutoff);

        if ($this->usesSoftDeletes($spec) && Schema::hasColumn($spec['table'], 'deleted_at')) {
            $base->whereNull('deleted_at');
        }

        $ageEligible = (clone $base)->count();

        $activeHolds = $this->holds->activeHolds($workspaceId);
        $workspaceHeld = $activeHolds->contains(fn ($hold): bool => $hold->profile_id === null);

        if ($workspaceHeld) {
            return $this->finish(
                type: $type,
                dryRun: $dryRun,
                evaluated: 0,
                purged: 0,
                held: $ageEligible,
            );
        }

        $eligible = clone $base;

        if (Schema::hasColumn($spec['table'], 'profile_id')) {
            $heldProfiles = $activeHolds
                ->whereNotNull('profile_id')
                ->pluck('profile_id')
                ->unique()
                ->values()
                ->all();

            if ($heldProfiles !== []) {
                $eligible->whereNotIn('profile_id', $heldProfiles);
            }
        }

        $evaluated = (clone $eligible)->count();
        $held = max(0, $ageEligible - $evaluated);

        $ids = (clone $eligible)
            ->orderBy($spec['date_column'])
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($dryRun || $ids === []) {
            return $this->finish(
                type: $type,
                dryRun: $dryRun,
                evaluated: $evaluated,
                purged: 0,
                held: $held,
            );
        }

        $purged = $this->deleteRows($spec, $ids);

        return $this->finish(
            type: $type,
            dryRun: false,
            evaluated: $evaluated,
            purged: $purged,
            held: $held,
        );
    }

    /**
     * Scan every tenant/workspace on the platform and run one batch per type.
     *
     * @param  list<string>  $types
     * @return array<string, PurgeReport> keyed by type
     */
    public function purgeAll(array $types, int $limit = 500): array
    {
        $reports = [];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            DB::transaction(function () use ($tenantId, $types, $limit, &$reports): void {
                TenantContext::run($tenantId, '', function () use ($tenantId, $types, $limit, &$reports): void {
                    $workspaceIds = Workspace::query()->orderBy('id')->pluck('id');

                    foreach ($workspaceIds as $workspaceId) {
                        TenantContext::run(
                            $tenantId,
                            $workspaceId,
                            function () use ($types, $limit, &$reports): void {
                                foreach ($types as $type) {
                                    $report = $this->purgeBatch($type, $limit);

                                    $reports[$type] = isset($reports[$type])
                                        ? $reports[$type]->merge($report)
                                        : $report;
                                }
                            },
                        );
                    }
                });
            });
        }

        return $reports;
    }

    /**
     * Physically remove rows that were soft-deleted at least `$graceDays` ago.
     *
     * This is the second half of retention (RETENTION-MODEL.md §8, P1).
     * `purgeBatch()` only sets `deleted_at` for models that support soft
     * deletes, which honestly means "removed from the service", not "deleted":
     * the row is still in the database, in every dump and in every backup.
     * Compaction is what makes the deletion sentence in the privacy notice
     * true, so it carries the same safety rails as the purge:
     *
     *   - audit tables stay a hard-coded forbidden target (DM-R05);
     *   - an active legal hold skips the workspace or the row (DM-R04);
     *   - `retention.dry_run = true` deletes nothing, so compaction can never
     *     become a bypass around the operator's global switch;
     *   - object-backed rows lose their private object before the metadata row,
     *     and a row whose object delete fails is kept so the next run retries
     *     (`skipped = object_delete_failed`, fail closed);
     *   - one `security_audit_logs` row per batch, counts only (N-02);
     *   - a second run finds nothing, because the rows are physically gone.
     *
     * Rows are selected by `deleted_at` (the moment the member or the purge
     * deleted them), never by the retention `date_column`: the grace period
     * starts when the data was deleted, not when it was recorded. That is also
     * why this works for every type whose table has a `deleted_at` column,
     * including tables the purge hard-deletes directly: a row a member deleted
     * through the API is compacted even when the purge never touched it.
     *
     * @return array{compacted: int, held: int, skipped: string|null, evaluated: int, dry_run: bool}
     */
    public function compactBatch(string $type, int $graceDays, int $limit = 500): array
    {
        if ($graceDays < 1) {
            throw new InvalidArgumentException('Compaction grace period must be at least 1 day.');
        }

        if ($limit < 1) {
            throw new InvalidArgumentException('Compaction batch size must be at least 1.');
        }

        $this->assertTypeMayBePurged($type);

        $dryRun = (bool) config('retention.dry_run', true);

        $context = $this->activeContext($type);
        $workspaceId = $context['workspace_id'];

        $spec = self::TYPE_SPECS[$type] ?? null;

        if ($spec === null) {
            return $this->skippedCompaction($type, $dryRun, 'not_yet_implemented');
        }

        if (! Schema::hasTable($spec['table'])) {
            return $this->skippedCompaction($type, $dryRun, 'table_not_present');
        }

        // No soft-delete marker means nothing can be "already deleted" here;
        // the purge removes those rows physically in one step.
        if (! Schema::hasColumn($spec['table'], 'deleted_at')) {
            return $this->skippedCompaction($type, $dryRun, 'no_soft_deletes');
        }

        $cutoff = now()->subDays($graceDays);

        $base = DB::table($spec['table'])
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff);

        $ageEligible = (clone $base)->count();

        $activeHolds = $this->holds->activeHolds($workspaceId);
        $workspaceHeld = $activeHolds->contains(fn ($hold): bool => $hold->profile_id === null);

        if ($workspaceHeld) {
            return $this->finishCompaction(
                type: $type,
                dryRun: $dryRun,
                graceDays: $graceDays,
                evaluated: 0,
                compacted: 0,
                held: $ageEligible,
                skipped: 'legal_hold',
            );
        }

        $eligible = clone $base;

        if (Schema::hasColumn($spec['table'], 'profile_id')) {
            $heldProfiles = $activeHolds
                ->whereNotNull('profile_id')
                ->pluck('profile_id')
                ->unique()
                ->values()
                ->all();

            if ($heldProfiles !== []) {
                $eligible->whereNotIn('profile_id', $heldProfiles);
            }
        }

        $evaluated = (clone $eligible)->count();
        $held = max(0, $ageEligible - $evaluated);

        $ids = (clone $eligible)
            ->orderBy('deleted_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($dryRun || $ids === []) {
            return $this->finishCompaction(
                type: $type,
                dryRun: $dryRun,
                graceDays: $graceDays,
                evaluated: $evaluated,
                compacted: 0,
                held: $held,
                skipped: null,
            );
        }

        $result = $this->compactRows($spec, $ids);

        return $this->finishCompaction(
            type: $type,
            dryRun: false,
            graceDays: $graceDays,
            evaluated: $evaluated,
            compacted: $result['compacted'],
            held: $held,
            skipped: $result['skipped'],
        );
    }

    /**
     * Run one compaction pump per type for every tenant/workspace.
     *
     * @param  list<string>  $types
     * @return array<string, array{compacted: int, held: int, skipped: string|null, evaluated: int, dry_run: bool}> keyed by type
     */
    public function compactAll(array $types, int $graceDays, int $limit = 500): array
    {
        $reports = [];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            DB::transaction(function () use ($tenantId, $types, $graceDays, $limit, &$reports): void {
                TenantContext::run($tenantId, '', function () use ($tenantId, $types, $graceDays, $limit, &$reports): void {
                    $workspaceIds = Workspace::query()->orderBy('id')->pluck('id');

                    foreach ($workspaceIds as $workspaceId) {
                        TenantContext::run(
                            $tenantId,
                            $workspaceId,
                            function () use ($types, $graceDays, $limit, &$reports): void {
                                foreach ($types as $type) {
                                    $report = $this->compactBatch($type, $graceDays, $limit);

                                    $reports[$type] = isset($reports[$type])
                                        ? $this->mergeCompaction($reports[$type], $report)
                                        : $report;
                                }
                            },
                        );
                    }
                });
            });
        }

        return $reports;
    }

    /**
     * Guard that no configuration or caller can point the purge at audit data.
     */
    public function assertTypeMayBePurged(string $type): void
    {
        if (in_array($type, self::FORBIDDEN_TYPES, true)) {
            throw new InvalidArgumentException(
                "Retention purge must never target audit data [{$type}] (DM-R05)."
            );
        }

        $spec = self::TYPE_SPECS[$type] ?? null;

        if ($spec !== null && in_array($spec['table'], self::FORBIDDEN_TYPES, true)) {
            throw new InvalidArgumentException(
                "Retention purge must never target audit table [{$spec['table']}] (DM-R05)."
            );
        }
    }

    /**
     * @param  array{table: string, date_column: string, model: class-string|null, soft_deletes: bool, objects?: bool}  $spec
     * @param  list<string>  $ids
     */
    private function deleteRows(array $spec, array $ids): int
    {
        // `task_events` is append-only for every application path; the trigger
        // only permits DELETE when retention explicitly opts in. Cascades from
        // `care_tasks` hit the same trigger, so the flag is set for every type.
        DB::selectOne('select set_config(?, ?, false)', ['app.retention_purge', 'on']);

        try {
            // Object-backed rows: remove the private object first. If that
            // fails, keep the metadata row so the next run can retry rather
            // than losing the only pointer to the object (fail closed).
            if (($spec['objects'] ?? false) === true) {
                $rows = MedicalDocument::withTrashed()
                    ->whereIn('id', $ids)
                    ->get();

                foreach ($rows as $row) {
                    $path = $row->storage_path;

                    if (! is_string($path) || $path === '') {
                        continue;
                    }

                    $disk = Storage::disk($row->storage_disk);

                    if ($disk->exists($path) && ! $disk->delete($path)) {
                        throw new \RuntimeException(
                            'Retention could not delete a medical document object; metadata was kept.'
                        );
                    }
                }

                return DB::table($spec['table'])->whereIn('id', $ids)->delete();
            }

            $model = $spec['model'];

            if ($model !== null) {
                /** @var class-string<Model> $model */
                $rows = $model::query()->whereIn('id', $ids)->get();

                foreach ($rows as $row) {
                    $row->delete();
                }

                return $rows->count();
            }

            return DB::table($spec['table'])->whereIn('id', $ids)->delete();
        } finally {
            DB::selectOne('select set_config(?, ?, false)', ['app.retention_purge', '']);
        }
    }

    /**
     * @param  array{table: string, date_column: string, model: class-string|null, soft_deletes: bool, objects?: bool}  $spec
     */
    private function usesSoftDeletes(array $spec): bool
    {
        return $spec['soft_deletes'] && $spec['model'] !== null;
    }

    /**
     * Resolve and validate the tenant/workspace context a batch needs.
     *
     * @return array{tenant: Tenant, workspace_id: string}
     */
    private function activeContext(string $type): array
    {
        $tenantId = TenantContext::tenantId();
        $workspaceId = TenantContext::workspaceId();

        if ($tenantId === null || $workspaceId === null || $workspaceId === '') {
            throw new LogicException(
                'Retention purge requires an explicit tenant/workspace context (N-01/F-09).'
            );
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            throw new LogicException('Retention purge context points at an unknown tenant.');
        }

        // Unknown-but-recognised retention types are reported, not guessed at.
        $defaults = (array) config('retention.defaults', []);
        if (! array_key_exists($type, $defaults)) {
            throw new InvalidArgumentException("Unknown retention type [{$type}].");
        }

        return ['tenant' => $tenant, 'workspace_id' => $workspaceId];
    }

    /**
     * Physically delete already soft-deleted rows.
     *
     * @param  array{table: string, date_column: string, model: class-string|null, soft_deletes: bool, objects?: bool}  $spec
     * @param  list<string>  $ids
     * @return array{compacted: int, skipped: string|null}
     */
    private function compactRows(array $spec, array $ids): array
    {
        // The append-only trigger on `task_events` only allows DELETE while
        // this flag is set, and a cascade from another table hits it too.
        DB::selectOne('select set_config(?, ?, false)', ['app.retention_purge', 'on']);

        try {
            if (($spec['objects'] ?? false) === true) {
                $rows = MedicalDocument::withTrashed()
                    ->whereIn('id', $ids)
                    ->get();

                $kept = [];

                foreach ($rows as $row) {
                    $path = $row->storage_path;

                    if (! is_string($path) || $path === '') {
                        continue;
                    }

                    $disk = Storage::disk($row->storage_disk);

                    // An object delete that fails must not leave a metadata row
                    // pointing at bytes nobody will remove on the next run.
                    if ($disk->exists($path) && ! $disk->delete($path)) {
                        $kept[] = (string) $row->id;
                    }
                }

                $deleteIds = array_values(array_diff($ids, $kept));

                $compacted = $deleteIds === []
                    ? 0
                    : DB::table($spec['table'])->whereIn('id', $deleteIds)->delete();

                return [
                    'compacted' => $compacted,
                    'skipped' => $kept === [] ? null : 'object_delete_failed',
                ];
            }

            $model = $spec['model'];

            // Soft-deleted rows are invisible to the default query scope, and
            // `delete()` would only set `deleted_at` again, so this path needs
            // an explicit force delete.
            if ($model !== null && method_exists($model, 'withTrashed')) {
                /** @var class-string<Model> $model */
                $rows = $model::withTrashed()->whereIn('id', $ids)->get();

                $compacted = 0;

                foreach ($rows as $row) {
                    $row->forceDelete();
                    $compacted++;
                }

                return ['compacted' => $compacted, 'skipped' => null];
            }

            return [
                'compacted' => DB::table($spec['table'])->whereIn('id', $ids)->delete(),
                'skipped' => null,
            ];
        } finally {
            DB::selectOne('select set_config(?, ?, false)', ['app.retention_purge', '']);
        }
    }

    /**
     * Keep the per-type aggregate truthful when several workspaces report.
     *
     * A skip reason survives the merge only when every workspace reported the
     * same one (a schema-level skip, or a platform-wide legal hold). A failed
     * object delete always survives, because it must fail the command.
     *
     * @param  array{compacted: int, held: int, skipped: string|null, evaluated: int, dry_run: bool}  $first
     * @param  array{compacted: int, held: int, skipped: string|null, evaluated: int, dry_run: bool}  $second
     * @return array{compacted: int, held: int, skipped: string|null, evaluated: int, dry_run: bool}
     */
    private function mergeCompaction(array $first, array $second): array
    {
        $skipped = $first['skipped'] === $second['skipped'] ? $first['skipped'] : null;

        if ($first['skipped'] === 'object_delete_failed' || $second['skipped'] === 'object_delete_failed') {
            $skipped = 'object_delete_failed';
        }

        return [
            'compacted' => $first['compacted'] + $second['compacted'],
            'held' => $first['held'] + $second['held'],
            'skipped' => $skipped,
            'evaluated' => $first['evaluated'] + $second['evaluated'],
            'dry_run' => $first['dry_run'],
        ];
    }

    /**
     * Report a batch that never started (unknown type, missing table, no
     * soft-delete marker). Nothing happened, so no audit row is written -
     * the same rule a skipped purge batch follows.
     *
     * @return array{compacted: int, held: int, skipped: string|null, evaluated: int, dry_run: bool}
     */
    private function skippedCompaction(string $type, bool $dryRun, string $reason): array
    {
        return [
            'compacted' => 0,
            'held' => 0,
            'skipped' => $reason,
            'evaluated' => 0,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Audit one compaction batch and return its counts.
     *
     * A held batch is audited with its `held` count, because there *was* work
     * that a legal hold blocked. An `object_delete_failed` batch is audited
     * with the rows it did remove, so a partial deletion is never silent.
     *
     * @return array{compacted: int, held: int, skipped: string|null, evaluated: int, dry_run: bool}
     */
    private function finishCompaction(
        string $type,
        bool $dryRun,
        int $graceDays,
        int $evaluated,
        int $compacted,
        int $held,
        ?string $skipped = null,
    ): array {
        $this->audit->record([
            'actor_type' => 'system',
            'action' => 'retention.compact',
            'target_type' => 'retention_batch',
            'target_id' => null,
            'result' => 'allow',
            'reason_code' => $dryRun ? 'dry_run' : $skipped,
            'metadata' => [
                'retention_type' => $type,
                'dry_run' => $dryRun,
                'grace_days' => $graceDays,
                'evaluated' => $evaluated,
                'compacted' => $compacted,
                'held' => $held,
                'skipped' => $skipped,
            ],
        ]);

        if ($dryRun) {
            Log::info('retention.compact.dry_run', [
                'retention_type' => $type,
                'grace_days' => $graceDays,
                'evaluated' => $evaluated,
                'held' => $held,
            ]);
        }

        return [
            'compacted' => $compacted,
            'held' => $held,
            'skipped' => $skipped,
            'evaluated' => $evaluated,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  array{table: string, date_column: string, model: class-string|null, soft_deletes: bool}  $spec
     */
    private function finish(
        string $type,
        bool $dryRun,
        int $evaluated,
        int $purged,
        int $held,
    ): PurgeReport {
        // One audit row per batch, counts only. A dry-run batch is audited too
        // so the 30-day observation window leaves a trace (ADR-016 §5).
        $this->audit->record([
            'actor_type' => 'system',
            'action' => 'retention.purge',
            'target_type' => 'retention_batch',
            'target_id' => null,
            'result' => 'allow',
            'reason_code' => $dryRun ? 'dry_run' : null,
            'metadata' => [
                'retention_type' => $type,
                'dry_run' => $dryRun,
                'evaluated' => $evaluated,
                'purged' => $purged,
                'held' => $held,
            ],
        ]);

        if ($dryRun) {
            Log::info('retention.purge.dry_run', [
                'retention_type' => $type,
                'evaluated' => $evaluated,
                'held' => $held,
            ]);
        }

        return new PurgeReport(
            type: $type,
            evaluated: $evaluated,
            purged: $purged,
            held: $held,
            dryRun: $dryRun,
        );
    }
}
