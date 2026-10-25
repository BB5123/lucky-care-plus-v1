<?php

namespace App\Console\Commands;

use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Advances every due workspace along the retention states (P2).
 *
 * The ladder is `read_only -> archived -> scheduled_deletion`. The final arrow
 * (`scheduled_deletion -> deleted`) is not automated in P2: the member's "delete
 * now" (P3) or the archive deletion (P4) owns it, and this command reports a
 * due scheduled_deletion row as `waiting` instead of pretending the data is gone.
 *
 * A workspace with an active legal hold is reported as `held` and is never
 * advanced - the hold pins the account in `read_only` (RET-T02).
 */
final class RetentionAdvanceCommand extends Command
{
    protected $signature = 'retention:advance
        {--dry-run : Report the transitions without writing them}';

    protected $description = 'Move lapsed workspaces along read_only -> archived -> scheduled_deletion when due';

    public function handle(WorkspaceLifecycle $lifecycle): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = [];

        try {
            foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
                DB::transaction(function () use ($tenantId, $dryRun, $lifecycle, &$rows): void {
                    TenantContext::run($tenantId, '', function () use ($tenantId, $dryRun, $lifecycle, &$rows): void {
                        $workspaceIds = Workspace::query()
                            ->whereIn('retention_state', [
                                RetentionState::ReadOnly->value,
                                RetentionState::Archived->value,
                            ])
                            ->orderBy('id')
                            ->pluck('id');

                        foreach ($workspaceIds as $workspaceId) {
                            TenantContext::run(
                                $tenantId,
                                $workspaceId,
                                function () use ($workspaceId, $dryRun, $lifecycle, &$rows): void {
                                    $workspace = Workspace::query()->find($workspaceId);

                                    if (! $workspace instanceof Workspace || ! $lifecycle->isDue($workspace)) {
                                        return;
                                    }

                                    $from = $lifecycle->state($workspace);
                                    $target = $from->nextOnExpiry();

                                    if ($dryRun) {
                                        $rows[] = [
                                            $workspaceId,
                                            $from->value,
                                            $target?->value ?? '-',
                                            $lifecycle->held($workspace) ? 'held' : 'due',
                                        ];

                                        return;
                                    }

                                    $advanced = $lifecycle->advance($workspace);

                                    $rows[] = [
                                        $workspaceId,
                                        $from->value,
                                        $advanced !== null ? $lifecycle->state($workspace)->value : $from->value,
                                        match (true) {
                                            $advanced !== null => 'advanced',
                                            $lifecycle->held($workspace) => 'held',
                                            default => 'waiting',
                                        },
                                    ];
                                },
                            );
                        }
                    });
                });
            }
        } catch (Throwable $exception) {
            $this->error('Retention advance failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->table(['Workspace', 'From', 'To', 'Result'], $rows);

        if ($rows === []) {
            $this->info('No workspace window is due.');
        } elseif ($dryRun) {
            $this->info('Dry run: no state was changed.');
        }

        $held = count(array_filter($rows, static fn (array $row): bool => $row[3] === 'held'));

        if ($held > 0) {
            $this->warn("{$held} workspace(s) are pinned by a legal hold and were not advanced.");
        }

        return self::SUCCESS;
    }
}
