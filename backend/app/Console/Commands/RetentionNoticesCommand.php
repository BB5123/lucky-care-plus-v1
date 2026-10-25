<?php

namespace App\Console\Commands;

use App\Modules\Retention\Services\RetentionNoticeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends the 30/7/1-day notices for accounts scheduled for deletion (P3, R7).
 *
 * The command is a scheduler entry, not a support tool: it scans every tenant
 * and is idempotent through the delivery ledger, so re-running it the same
 * night sends nothing. `--dry-run` reports the due notices without writing any
 * delivery row and without sending mail.
 */
final class RetentionNoticesCommand extends Command
{
    protected $signature = 'retention:notices
        {--dry-run : Report the notices without writing anything}';

    protected $description = 'Send the 30/7/1-day retention notices for accounts scheduled for deletion';

    public function handle(RetentionNoticeService $notices): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $rows = $notices->dispatchDue(null, $dryRun);
        } catch (Throwable $exception) {
            $this->error('Retention notices failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->table(
            ['Workspace', 'Days left', 'Notice', 'Recipients', 'Status'],
            array_map(static fn (array $row): array => [
                substr($row['workspace_id'], 0, 8),
                $row['days_left'],
                $row['template'],
                $row['recipients'],
                $row['status'],
            ], $rows),
        );

        if ($rows === []) {
            $this->info('No retention notice is due.');
        } elseif ($dryRun) {
            $this->info('Dry run: no notice was sent and no delivery row was written.');
        }

        return self::SUCCESS;
    }
}
