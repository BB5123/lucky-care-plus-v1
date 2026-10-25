<?php

namespace App\Console\Commands;

use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceResolver;
use App\Support\Database\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Support action: record that a workspace's subscription lapsed (P2).
 *
 * Billing does not exist yet (D5), so the lapse itself is an explicit,
 * server-side action rather than an automatic one. When billing arrives it calls
 * the same `WorkspaceLifecycle::lapse()`; the command stays as the support path
 * for payment failures and heir requests.
 *
 * The command is deliberately narrow: one workspace, an explicit reason, and an
 * audit row. It never guesses which workspace lapsed.
 */
final class RetentionLapseCommand extends Command
{
    protected $signature = 'retention:lapse
        {workspace : Workspace UUID}
        {--reason=manual : Why the account lapsed (payment, support, heir, rehearsal)}
        {--dry-run : Report the transition without writing it}';

    protected $description = 'Move a workspace into read_only because its subscription lapsed';

    public function handle(WorkspaceResolver $resolver, WorkspaceLifecycle $lifecycle): int
    {
        $workspace = $resolver->resolve((string) $this->argument('workspace'));

        if (! $workspace instanceof Workspace) {
            $this->error('Unknown workspace.');

            return self::FAILURE;
        }

        $before = $lifecycle->state($workspace)->value;

        if ((bool) $this->option('dry-run')) {
            $this->info("Would lapse {$workspace->id}: {$before} -> read_only.");
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        try {
            TenantContext::run($workspace->tenant_id, $workspace->id, function () use ($workspace, $lifecycle): void {
                $lifecycle->lapse($workspace, (string) $this->option('reason'));
            });
        } catch (Throwable $exception) {
            $this->error('Retention lapse failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Workspace %s: %s -> %s, read-only until %s.',
            $workspace->id,
            $before,
            $lifecycle->state($workspace)->value,
            $workspace->retention_expires_at?->toIso8601String() ?? '-',
        ));

        return self::SUCCESS;
    }
}
