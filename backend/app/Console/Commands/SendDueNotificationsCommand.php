<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Application\ReminderDispatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hourly `task.due_soon` scan (`.ai/CRM_DOMAIN_ARCHITECTURE.md §8`).
 *
 * Selects non-terminal care tasks due inside the next 24 hours, and dispatches
 * one reminder to the active primary assignee. The ledger existence check in
 * {@see ReminderDispatcher} makes a second run a no-op; the queue is not part
 * of the idempotency guarantee.
 *
 * The table prints ids, user ids and timestamps only — never a task title,
 * description, or any health value (N-02).
 */
final class SendDueNotificationsCommand extends Command
{
    protected $signature = 'notifications:send-due
        {--dry-run : Report what would be sent without writing anything}';

    protected $description = 'Send due-soon reminders for non-terminal care tasks due in the next 24 hours';

    public function handle(ReminderDispatcher $reminders): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $rows = $reminders->dueTaskReminders($dryRun);
        } catch (Throwable $exception) {
            $this->error('Due-soon notification scan failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->table(
            ['Workspace', 'Task', 'Recipient', 'Due at', 'Status'],
            array_map(fn (array $row): array => [
                substr($row['workspace_id'], 0, 8),
                $row['subject_id'],
                $row['recipient_user_id'] > 0 ? (string) $row['recipient_user_id'] : '-',
                $row['at'],
                $row['status'],
            ], $rows),
        );

        $sent = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'sent'));
        $wouldSend = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'would_send'));

        if ($dryRun) {
            $this->info("Dry run: {$wouldSend} due-soon notification(s) would be sent; nothing was written.");
        } else {
            $this->info("Sent {$sent} due-soon notification(s).");
        }

        return self::SUCCESS;
    }
}
