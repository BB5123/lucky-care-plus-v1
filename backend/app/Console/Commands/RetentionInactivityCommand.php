<?php

namespace App\Console\Commands;

use App\Modules\Retention\Services\InactivityService;
use App\Modules\Retention\Services\InactivityStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Daily inactivity scan (ADR-016 §2).
 *
 * Prints the workspaces inside the 30/14/7-day reminder window and the ones
 * already purge-due. Sending is a stub that only logs — the Notifications
 * module does not exist yet.
 */
final class RetentionInactivityCommand extends Command
{
    protected $signature = 'retention:inactivity
        {--dry-run : Show the schedule without dispatching the notification stub}';

    protected $description = 'Find inactive workspaces and compute the purge reminder schedule';

    public function handle(InactivityService $inactivity): int
    {
        $now = Carbon::now()->utc();

        try {
            $statuses = $inactivity->scan($now);
        } catch (Throwable $e) {
            $this->error('Inactivity scan failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $inWindow = array_values(array_filter(
            $statuses,
            fn (InactivityStatus $status): bool => $status->inNotificationWindow($now),
        ));

        $rows = array_map(function (InactivityStatus $status): array {
            $schedule = [];

            foreach ($status->schedule as $entry) {
                $schedule[$entry['days_before']] = $entry['due_at']->format('Y-m-d');
            }

            return [
                $status->workspaceId,
                substr($status->tenantId, 0, 8),
                $status->lastActivityAt->format('Y-m-d'),
                $status->purgeDueAt->format('Y-m-d'),
                $schedule[30] ?? '-',
                $schedule[14] ?? '-',
                $schedule[7] ?? '-',
                $status->purgeDue ? 'purge due' : 'reminder window',
            ];
        }, $inWindow);

        $this->table(
            ['Workspace', 'Tenant', 'Last activity', 'Purge due', '-30d', '-14d', '-7d', 'Status'],
            $rows,
        );

        if ($rows === []) {
            $this->info('No workspace is inside the inactivity window.');
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run: no reminders were dispatched.');

            return self::SUCCESS;
        }

        $sent = $inactivity->dispatchDueNotifications($statuses, $now);

        $this->info(
            "Reminder stub: logged {$sent} due notification(s). "
            .'Actual delivery waits for the Notifications module.'
        );

        return self::SUCCESS;
    }
}
