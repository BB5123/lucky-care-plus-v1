<?php

namespace App\Modules\Retention\Services;

use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
 *     a safe no-op (DM-R07).
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
     * @var array<string, array{table: string, date_column: string, model: class-string|null, soft_deletes: bool}>
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
            'table' => 'documents',
            'date_column' => 'created_at',
            'model' => null,
            'soft_deletes' => false,
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
     * @param  array{table: string, date_column: string, model: class-string|null, soft_deletes: bool}  $spec
     * @param  list<string>  $ids
     */
    private function deleteRows(array $spec, array $ids): int
    {
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
    }

    /**
     * @param  array{table: string, date_column: string, model: class-string|null, soft_deletes: bool}  $spec
     */
    private function usesSoftDeletes(array $spec): bool
    {
        return $spec['soft_deletes'] && $spec['model'] !== null;
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
