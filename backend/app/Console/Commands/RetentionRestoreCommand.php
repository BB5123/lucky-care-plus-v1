<?php

namespace App\Console\Commands;

use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceResolver;
use App\Support\Database\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Support action: return a lapsed or archived workspace to active (P2).
 *
 * The model allows restore after payment, a support request, or an heir
 * request (RETENTION-MODEL.md section 2). This is the audited path for all
 * three; a self-service "pay to restore" screen is P3.
 */
final class RetentionRestoreCommand extends Command
{
    protected $signature = 'retention:restore
        {workspace : Workspace UUID}
        {--reason=support : Why the account is restored (payment, support, heir)}
        {--dry-run : Report the transition without writing it}';

    protected $description = 'Return a lapsed or archived workspace to active';

    public function handle(WorkspaceResolver $resolver, WorkspaceLifecycle $lifecycle): int
    {
        $workspace = $resolver->resolve((string) $this->argument('workspace'));

        if (! $workspace instanceof Workspace) {
            $this->error('Unknown workspace.');

            return self::FAILURE;
        }

        $before = $lifecycle->state($workspace)->value;

        if ((bool) $this->option('dry-run')) {
            $this->info("Would restore {$workspace->id}: {$before} -> active.");
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        try {
            TenantContext::run($workspace->tenant_id, $workspace->id, function () use ($workspace, $lifecycle): void {
                $lifecycle->restore($workspace, (string) $this->option('reason'));
            });
        } catch (Throwable $exception) {
            $this->error('Retention restore failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Workspace %s: %s -> %s.',
            $workspace->id,
            $before,
            $lifecycle->state($workspace)->value,
        ));

        return self::SUCCESS;
    }
}
