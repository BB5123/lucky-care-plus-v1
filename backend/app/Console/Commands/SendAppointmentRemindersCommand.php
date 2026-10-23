<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Application\ReminderDispatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily `appt.reminder` scan (`.ai/CRM_DOMAIN_ARCHITECTURE.md §8`).
 *
 * Selects planned/confirmed appointments inside the next 24 hours and notifies
 * the profile owner plus the active primary assignee of the transport task,
 * when one is linked. Idempotency is the ledger existence check, so an
 * appointment already notified by the creation listener is skipped.
 *
 * Output is ids, user ids and timestamps only; `purpose`/`location` never
 * appear in a command table or a log (N-02).
 */
final class SendAppointmentRemindersCommand extends Command
{
    protected $signature = 'notifications:send-appointment-reminders
        {--dry-run : Report what would be sent without writing anything}';

    protected $description = 'Send appointment reminders for planned/confirmed visits in the next 24 hours';

    public function handle(ReminderDispatcher $reminders): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $rows = $reminders->appointmentReminders($dryRun);
        } catch (Throwable $exception) {
            $this->error('Appointment reminder scan failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->table(
            ['Workspace', 'Appointment', 'Recipient', 'Scheduled at', 'Status'],
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
            $this->info("Dry run: {$wouldSend} appointment reminder(s) would be sent; nothing was written.");
        } else {
            $this->info("Sent {$sent} appointment reminder(s).");
        }

        return self::SUCCESS;
    }
}
