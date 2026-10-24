<?php

namespace App\Console\Commands;

use App\Modules\CareCrm\Application\CareTaskMaterializer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily materialisation of recurring care-task templates (P2.1).
 *
 * `--days` is the look-ahead window; `--dry-run` walks the same schedules and
 * reports what would be created without writing a single row. Idempotency
 * lives in the materialiser and the unique `(template_id, occurrence_key)`
 * index, so running this command twice is safe.
 */
final class MaterialiseCareTasksCommand extends Command
{
    protected $signature = 'tasks:materialise
        {--days=14 : How many days ahead to materialise}
        {--dry-run : Report what would be created without writing anything}';

    protected $description = 'Materialise recurring care-task templates into scheduled occurrences';

    public function handle(CareTaskMaterializer $materializer): int
    {
        $days = filter_var(
            $this->option('days'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 365]],
        );

        if ($days === false) {
            $this->error('The --days option must be an integer between 0 and 365.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $summary = $materializer->materialise($days, null, $dryRun);
        } catch (Throwable $exception) {
            $this->error('Recurring task materialisation failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->table(
            ['Templates', 'Created', 'Skipped', 'Dry run'],
            [[
                $summary['templates'],
                $summary['created'],
                $summary['skipped'],
                $dryRun ? 'yes' : 'no',
            ]],
        );

        if ($dryRun) {
            $this->info(
                "Dry run: {$summary['created']} occurrence(s) would be created across "
                ."{$summary['templates']} template(s); nothing was written."
            );
        } else {
            $this->info(
                "Created {$summary['created']} occurrence(s) across {$summary['templates']} template(s)."
            );
        }

        return self::SUCCESS;
    }
}
