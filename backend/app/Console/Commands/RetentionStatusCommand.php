<?php

namespace App\Console\Commands;

use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Retention\Services\ArchiveBundleService;
use App\Modules\Retention\Services\ArchiveManifestService;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Operator report for the retention state machine (2026-10-26).
 *
 * Turning retention on is a per-deployment act (`RETENTION_DRY_RUN=false` plus a
 * real mail relay). Before that, an operator needs the answer to "how many
 * accounts are where, which are due tonight, is anything held, and do the
 * archives verify?" - without opening the database by hand.
 *
 * `--verify-archives` runs the same checks as `retention:archive-drill` for every
 * archived workspace and makes the command exit non-zero when any of them fails,
 * so it can be used as a monitoring probe. Without the flag it only lists.
 */
final class RetentionStatusCommand extends Command
{
    protected $signature = 'retention:status
        {--json : Machine-readable output}
        {--verify-archives : Verify every archived workspace bundle}';

    protected $description = 'Report workspaces per retention state, due windows, holds and archive integrity';

    public function handle(
        WorkspaceLifecycle $lifecycle,
        ArchiveManifestService $manifests,
        ArchiveBundleService $bundles,
    ): int {
        $now = now();

        $states = array_fill_keys(array_map(static fn (RetentionState $state): string => $state->value, RetentionState::cases()), 0);
        $due = [];
        $upcoming = [];
        $held = 0;
        $archived = [];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run($tenantId, '', function () use ($tenantId, $lifecycle, $now, &$states, &$due, &$upcoming, &$held, &$archived): void {
                $workspaces = Workspace::query()
                    ->select(['id', 'name', 'retention_state', 'retention_expires_at'])
                    ->orderBy('id')
                    ->get();

                foreach ($workspaces as $workspace) {
                    $state = $workspace->retentionState();
                    $states[$state->value] = ($states[$state->value] ?? 0) + 1;

                    // `held()` reads legal holds under RLS, so it needs this
                    // workspace's context, not just the tenant's.
                    $isHeld = TenantContext::run(
                        $tenantId,
                        (string) $workspace->id,
                        fn (): bool => $lifecycle->held($workspace),
                    );

                    if ($isHeld) {
                        $held++;

                        continue;
                    }

                    $row = [
                        'workspace_id' => $workspace->id,
                        'state' => $state->value,
                        'expires_at' => $workspace->retention_expires_at?->toIso8601String(),
                    ];

                    if ($lifecycle->isDue($workspace, $now)) {
                        $due[] = $row;
                    } elseif ($this->within($workspace->retention_expires_at, $now, 30)) {
                        $upcoming[] = $row;
                    }

                    if (in_array($state, [RetentionState::Archived, RetentionState::ScheduledDeletion], true)) {
                        $archived[] = ['tenant_id' => $tenantId, 'id' => $workspace->id, 'state' => $state->value];
                    }
                }
            });
        }

        $checks = [];

        if ((bool) $this->option('verify-archives')) {
            foreach ($archived as $entry) {
                $checks[] = $this->checkWorkspace($entry['tenant_id'], $entry['id'], $manifests, $bundles);
            }
        }

        $failed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['bundle_ok'] !== true || $check['inventory_ok'] !== true,
        ));
        $ok = $failed === [];

        $payload = [
            'dry_run' => (bool) config('retention.dry_run'),
            'states' => $states,
            'due' => $due,
            'upcoming' => $upcoming,
            'held' => $held,
            'archives' => $checks,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line('Retention dry run: '.($payload['dry_run'] ? 'true (nothing is deleted)' : 'false (live)'));

        $this->table(
            ['State', 'Workspaces'],
            array_map(static fn (string $state, int $count): array => [$state, $count], array_keys($states), $states),
        );

        $this->line('Held (legal hold) workspaces: '.$held);

        if ($due !== []) {
            $this->warn('Due now:');
            $this->table(
                ['Workspace', 'State', 'Expired at'],
                array_map(static fn (array $row): array => [$row['workspace_id'], $row['state'], $row['expires_at'] ?? '-'], $due),
            );
        } else {
            $this->line('Nothing is due now.');
        }

        $this->line('Upcoming within 30 days: '.count($upcoming));

        if ((bool) $this->option('verify-archives')) {
            if ($checks === []) {
                $this->line('No archived workspaces to verify.');
            } else {
                $this->table(
                    ['Workspace', 'State', 'Bundle', 'Inventory'],
                    array_map(
                        static fn (array $check): array => [
                            $check['workspace_id'],
                            $check['state'],
                            $check['archive_path'] === null ? 'missing' : ($check['bundle_ok'] ? 'ok' : 'FAILED'),
                            $check['inventory_ok'] ? 'ok' : 'FAILED',
                        ],
                        $checks,
                    ),
                );

                foreach ($checks as $check) {
                    foreach ($check['problems'] as $problem) {
                        $this->error($check['workspace_id'].': '.$problem);
                    }
                }
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function within(?CarbonInterface $expires, CarbonInterface $now, int $days): bool
    {
        return $expires !== null
            && $expires->greaterThan($now)
            && $expires->lessThanOrEqualTo($now->copy()->addDays($days));
    }

    /**
     * @return array{workspace_id: string, state: string, archive_path: ?string, bundle_ok: bool, inventory_ok: bool, problems: list<string>}
     */
    private function checkWorkspace(
        string $tenantId,
        string $workspaceId,
        ArchiveManifestService $manifests,
        ArchiveBundleService $bundles,
    ): array {
        return TenantContext::run($tenantId, $workspaceId, function () use ($workspaceId, $manifests, $bundles): array {
            $workspace = Workspace::query()->find($workspaceId);

            if (! $workspace instanceof Workspace) {
                return [
                    'workspace_id' => $workspaceId,
                    'state' => 'unknown',
                    'archive_path' => null,
                    'bundle_ok' => false,
                    'inventory_ok' => false,
                    'problems' => ['the workspace disappeared between the scan and the check'],
                ];
            }

            $manifest = WorkspaceArchiveManifest::query()
                ->where('workspace_id', $workspace->id)
                ->orderByDesc('archived_at')
                ->orderByDesc('id')
                ->first();

            if (! $manifest instanceof WorkspaceArchiveManifest) {
                return [
                    'workspace_id' => $workspace->id,
                    'state' => $workspace->retentionState()->value,
                    'archive_path' => null,
                    'bundle_ok' => false,
                    'inventory_ok' => false,
                    'problems' => ['no archive manifest exists'],
                ];
            }

            $result = $bundles->verify($manifest);

            return [
                'workspace_id' => $workspace->id,
                'state' => $workspace->retentionState()->value,
                'archive_path' => $manifest->archive_path,
                'bundle_ok' => $result['ok'],
                'inventory_ok' => $manifests->verify($manifest),
                'problems' => $result['problems'],
            ];
        });
    }
}
