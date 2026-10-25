<?php

namespace App\Console\Commands;

use App\Modules\Retention\Services\PurgeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Physical deletion of rows that were soft-deleted past the grace period (P1).
 *
 * `retention:purge` hides expired data (soft delete); this command is what
 * actually removes it, after a further grace window in which a restore or a
 * support request can still happen. It is a separate command on purpose: the
 * two actions run on different clocks and an operator must be able to run one
 * without the other.
 *
 * Exit code is non-zero for a real failure (invalid input, database or policy
 * error) and also when an object-backed row could not be removed, because that
 * is a storage incident the next run has to be paged for.
 */
final class RetentionCompactCommand extends Command
{
    protected $signature = 'retention:compact
        {--type= : Compact a single retention type instead of every type}
        {--older-than=30 : Days a row must have been soft-deleted before it is physically removed}
        {--limit=500 : Maximum rows per batch}
        {--dry-run : Report what would be compacted without deleting anything}';

    protected $description = 'Physically delete rows soft-deleted longer than the grace period (never audit data)';

    public function handle(PurgeService $purge): int
    {
        $graceDays = filter_var(
            $this->option('older-than'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($graceDays === false) {
            $this->error('The --older-than option must be a whole number of days, at least 1.');

            return self::FAILURE;
        }

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

            $reports = $purge->compactAll($types, $graceDays, $limit);
        } catch (Throwable $e) {
            $this->error('Retention compaction failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $dryRun = (bool) config('retention.dry_run', true);
        $objectFailure = false;

        $rows = [];

        foreach ($types as $type) {
            $report = $reports[$type] ?? [
                'compacted' => 0,
                'held' => 0,
                'skipped' => 'no_workspace',
                'evaluated' => 0,
                'dry_run' => $dryRun,
            ];

            if ($report['skipped'] === 'object_delete_failed') {
                $objectFailure = true;
            }

            $rows[] = [
                $type,
                $report['evaluated'],
                $report['compacted'],
                $report['held'],
                $report['dry_run'] ? 'yes' : 'no',
                $report['skipped'] ?? 'done',
            ];
        }

        $this->table(
            ['Type', 'Eligible', 'Compacted', 'Held', 'Dry run', 'Status'],
            $rows,
        );

        if ($reports === []) {
            $this->warn('No tenants/workspaces were found; nothing to compact.');
        }

        if ($dryRun) {
            $this->info('Dry run: no rows were deleted. "Eligible" is what would be compacted.');
        } else {
            $this->info('Retention compaction complete.');
        }

        if ($objectFailure) {
            $this->error(
                'At least one document object could not be deleted; its metadata row was kept for the next run.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
