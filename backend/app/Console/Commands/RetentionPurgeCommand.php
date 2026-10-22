<?php

namespace App\Console\Commands;

use App\Modules\Retention\Services\PurgeReport;
use App\Modules\Retention\Services\PurgeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily retention purge (ADR-016 §5).
 *
 * Exit code is non-zero only for a real failure (invalid input, database or
 * policy error). A workspace with nothing to delete is a success.
 */
final class RetentionPurgeCommand extends Command
{
    protected $signature = 'retention:purge
        {--type= : Purge a single retention type instead of every type}
        {--limit=500 : Maximum rows per batch}
        {--dry-run : Report what would be purged without deleting anything}';

    protected $description = 'Purge business data that has passed its retention period (never audit data)';

    public function handle(PurgeService $purge): int
    {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($limit === false) {
            $this->error('The --limit option must be a positive integer.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            config(['retention.dry_run' => true]);
        }

        $requestedType = $this->option('type');

        try {
            $defaults = (array) config('retention.defaults', []);

            if (is_string($requestedType) && $requestedType !== '') {
                $purge->assertTypeMayBePurged($requestedType);

                if (! array_key_exists($requestedType, $defaults)) {
                    throw new \InvalidArgumentException(
                        "Unknown retention type [{$requestedType}]."
                    );
                }

                $types = [$requestedType];
            } else {
                $types = $purge->purgeableTypes();
            }

            $reports = $purge->purgeAll($types, $limit);
        } catch (Throwable $e) {
            $this->error('Retention purge failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $dryRun = (bool) config('retention.dry_run', true);

        $rows = [];

        foreach ($types as $type) {
            $report = $reports[$type] ?? PurgeReport::skipped($type, 'no_workspace', $dryRun);

            $rows[] = [
                $report->type,
                $report->evaluated,
                $report->purged,
                $report->held,
                $report->dryRun ? 'yes' : 'no',
                $report->skipped ? ($report->reason ?? 'skipped') : 'done',
            ];
        }

        $this->table(
            ['Type', 'Eligible', 'Purged', 'Held', 'Dry run', 'Status'],
            $rows,
        );

        if ($reports === []) {
            $this->warn('No tenants/workspaces were found; nothing to purge.');
        }

        if ($dryRun) {
            $this->info('Dry run: no rows were deleted. "Eligible" is what would be purged.');
        } else {
            $this->info('Retention purge complete.');
        }

        return self::SUCCESS;
    }
}
