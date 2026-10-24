<?php

namespace App\Console\Commands;

use App\Modules\CareCrm\Application\CareTaskEscalator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled escalation ladder for overdue care tasks (P2.2).
 *
 * Runs every 15 minutes. `--dry-run` walks the same candidates and reports the
 * decision without writing a task event, audit row, assignment, or
 * notification. Idempotency is owned by the escalator (`escalation_level >=
 * target` is a no-op), not by the queue.
 *
 * The summary contains counts only — never a task title or person name (N-02).
 */
final class EscalateCareTasksCommand extends Command
{
    protected $signature = 'tasks:escalate
        {--dry-run : Report what would escalate without writing anything}
        {--limit=200 : Maximum number of overdue tasks to scan}';

    protected $description = 'Escalate overdue delegated and clinical care tasks';

    public function handle(CareTaskEscalator $escalator): int
    {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5000]],
        );

        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 5000.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $summary = $escalator->run($limit, $dryRun);
        } catch (Throwable $exception) {
            $this->error('Care-task escalation failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->table(
            ['Scanned', 'Escalated', 'Skipped', 'Reassigned', 'Dry run'],
            [[
                $summary['scanned'],
                $summary['escalated'],
                $summary['skipped'],
                $summary['reassigned'],
                $dryRun ? 'yes' : 'no',
            ]],
        );

        if ($dryRun) {
            $this->info(
                "Dry run: {$summary['escalated']} task(s) would be escalated "
                ."({$summary['reassigned']} reassigned); nothing was written."
            );
        } else {
            $this->info(
                "Escalated {$summary['escalated']} task(s); "
                ."{$summary['reassigned']} reassigned to a workspace admin."
            );
        }

        return self::SUCCESS;
    }
}
