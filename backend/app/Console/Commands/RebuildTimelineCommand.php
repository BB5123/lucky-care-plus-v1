<?php

namespace App\Console\Commands;

use App\Modules\Timeline\Application\TimelineRebuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rebuilds the timeline read model from its sources (CRM-011 / CRM-T03).
 *
 * Safe to run repeatedly: a second run reports zero changes. `--dry-run`
 * reports the same counters without touching a row.
 */
final class RebuildTimelineCommand extends Command
{
    protected $signature = 'timeline:rebuild
        {--profile= : Rebuild a single health profile by UUID}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Rebuild timeline_entries from health, medication, task and appointment sources';

    public function handle(TimelineRebuilder $rebuilder): int
    {
        $profileId = $this->option('profile');
        $profileId = is_string($profileId) && $profileId !== '' ? $profileId : null;

        if ($profileId !== null && ! Str::isUuid($profileId)) {
            $this->error('The --profile option must be a UUID.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $stats = $rebuilder->rebuild($profileId, $dryRun);
        } catch (Throwable $exception) {
            $this->error('Timeline rebuild failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $rows = [];
        $totals = ['created' => 0, 'updated' => 0, 'removed' => 0];

        foreach ($stats as $type => $counters) {
            $rows[] = [
                $type,
                $counters['created'],
                $counters['updated'],
                $counters['removed'],
            ];

            foreach ($totals as $counter => $value) {
                $totals[$counter] = $value + $counters[$counter];
            }
        }

        $this->table(['Type', 'Created', 'Updated', 'Removed'], $rows);

        $this->line(sprintf(
            'Total: created=%d updated=%d removed=%d',
            $totals['created'],
            $totals['updated'],
            $totals['removed'],
        ));

        if ($dryRun) {
            $this->info('Dry run: no rows were written or removed.');
        } else {
            $this->info('Timeline rebuild complete.');
        }

        return self::SUCCESS;
    }
}
