<?php

namespace App\Modules\Notifications\Application;

use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\Notifications\Jobs\DispatchNotificationJob;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the candidate list for the two scheduled notification commands.
 *
 * Both paths are idempotent by a ledger existence check — never by the queue —
 * so running a command twice in a row is a no-op. That check is scoped to the
 * template key and the subject id, which means it also covers rows written by
 * the event listeners (e.g. `appt.reminder` on appointment creation).
 *
 * Every workspace is entered through `TenantContext::run` inside a transaction
 * because RLS fails closed without the server-side tenant/workspace pair. The
 * commands scan tenants because a scheduler has no request context.
 */
final class ReminderDispatcher
{
    /** `due_soon` / `appt.reminder` look-ahead window. */
    public const WINDOW_HOURS = 24;

    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationDeliveryLedger $ledger,
    ) {}

    /**
     * @return list<array{
     *     workspace_id: string,
     *     subject_id: string,
     *     recipient_user_id: int,
     *     at: string,
     *     status: string
     * }>
     */
    public function dueTaskReminders(bool $dryRun, ?Carbon $now = null): array
    {
        $from = ($now ?? Carbon::now())->copy();
        $until = $from->copy()->addHours(self::WINDOW_HOURS);
        $rows = [];

        $this->eachWorkspace(function (string $tenantId, string $workspaceId) use (
            $dryRun,
            $from,
            $until,
            &$rows,
        ): void {
            $tasks = CareTask::query()
                ->where('tenant_id', $tenantId)
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('due_at')
                ->whereNotIn('status', [CareTask::STATUS_COMPLETED, CareTask::STATUS_CANCELLED])
                ->where('due_at', '>=', $from)
                ->where('due_at', '<=', $until)
                ->orderBy('due_at')
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $recipient = $this->recipients->taskPrimaryAssigneeUserId(
                    $task->id,
                    $tenantId,
                    $workspaceId,
                );

                if ($recipient === null) {
                    $rows[] = $this->row($workspaceId, $task->id, 0, $task->due_at, 'no_assignee');

                    continue;
                }

                if ($this->ledger->hasDelivery('task.due_soon', $task->id, $tenantId, $workspaceId)) {
                    $rows[] = $this->row($workspaceId, $task->id, $recipient, $task->due_at, 'already_sent');

                    continue;
                }

                if (! $dryRun) {
                    DispatchNotificationJob::dispatch(
                        tenantId: $tenantId,
                        workspaceId: $workspaceId,
                        templateKey: 'task.due_soon',
                        recipientUserIds: [$recipient],
                        params: [
                            'task_id' => $task->id,
                            'due_at' => $task->due_at->toIso8601String(),
                        ],
                        subjectType: 'care_task',
                        subjectId: $task->id,
                    );
                }

                $rows[] = $this->row(
                    $workspaceId,
                    $task->id,
                    $recipient,
                    $task->due_at,
                    $dryRun ? 'would_send' : 'sent',
                );
            }
        });

        return $rows;
    }

    /**
     * @return list<array{
     *     workspace_id: string,
     *     subject_id: string,
     *     recipient_user_id: int,
     *     at: string,
     *     status: string
     * }>
     */
    public function appointmentReminders(bool $dryRun, ?Carbon $now = null): array
    {
        $from = ($now ?? Carbon::now())->copy();
        $until = $from->copy()->addHours(self::WINDOW_HOURS);
        $rows = [];

        $this->eachWorkspace(function (string $tenantId, string $workspaceId) use (
            $dryRun,
            $from,
            $until,
            &$rows,
        ): void {
            $appointments = Appointment::query()
                ->where('tenant_id', $tenantId)
                ->where('workspace_id', $workspaceId)
                ->whereIn('status', [Appointment::STATUS_PLANNED, Appointment::STATUS_CONFIRMED])
                ->where('scheduled_at', '>=', $from)
                ->where('scheduled_at', '<=', $until)
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->get();

            foreach ($appointments as $appointment) {
                if ($this->ledger->hasDelivery(
                    'appt.reminder',
                    $appointment->id,
                    $tenantId,
                    $workspaceId,
                )) {
                    $rows[] = $this->row(
                        $workspaceId,
                        $appointment->id,
                        0,
                        $appointment->scheduled_at,
                        'already_sent',
                    );

                    continue;
                }

                $recipients = [];

                $owner = $this->recipients->profileOwnerUserId(
                    $appointment->profile_id,
                    $tenantId,
                    $workspaceId,
                );

                if ($owner !== null) {
                    $recipients[$owner] = true;
                }

                if ($appointment->transported_by_task_id !== null) {
                    $transport = $this->recipients->taskPrimaryAssigneeUserId(
                        $appointment->transported_by_task_id,
                        $tenantId,
                        $workspaceId,
                    );

                    if ($transport !== null) {
                        $recipients[$transport] = true;
                    }
                }

                $recipientIds = array_map('intval', array_keys($recipients));

                if ($recipientIds === []) {
                    $rows[] = $this->row(
                        $workspaceId,
                        $appointment->id,
                        0,
                        $appointment->scheduled_at,
                        'no_recipient',
                    );

                    continue;
                }

                if (! $dryRun) {
                    DispatchNotificationJob::dispatch(
                        tenantId: $tenantId,
                        workspaceId: $workspaceId,
                        templateKey: 'appt.reminder',
                        recipientUserIds: $recipientIds,
                        params: [
                            'appointment_id' => $appointment->id,
                            'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
                        ],
                        subjectType: 'appointment',
                        subjectId: $appointment->id,
                    );
                }

                foreach ($recipientIds as $recipientId) {
                    $rows[] = $this->row(
                        $workspaceId,
                        $appointment->id,
                        $recipientId,
                        $appointment->scheduled_at,
                        $dryRun ? 'would_send' : 'sent',
                    );
                }
            }
        });

        return $rows;
    }

    /**
     * @return array{workspace_id: string, subject_id: string, recipient_user_id: int, at: string, status: string}
     */
    private function row(
        string $workspaceId,
        string $subjectId,
        int $recipientUserId,
        \DateTimeInterface $at,
        string $status,
    ): array {
        return [
            'workspace_id' => $workspaceId,
            'subject_id' => $subjectId,
            'recipient_user_id' => $recipientUserId,
            'at' => $at->format(DATE_ATOM),
            'status' => $status,
        ];
    }

    /**
     * Run a callback once per (tenant, workspace) with a trusted context.
     *
     * Mirrors the retention scanner: one transaction per tenant so the
     * transaction-local `set_config` stays scoped to this batch.
     */
    private function eachWorkspace(callable $callback): void
    {
        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            $tenantId = (string) $tenantId;

            DB::transaction(function () use ($tenantId, $callback): void {
                TenantContext::run($tenantId, '', function () use ($tenantId, $callback): void {
                    // Only active accounts get reminders: a lapsed/archived
                    // workspace keeps its data but is not processed (P2b).
                    $workspaceIds = Workspace::query()
                        ->acceptingBackgroundWork()
                        ->orderBy('id')
                        ->pluck('id');

                    foreach ($workspaceIds as $workspaceId) {
                        $workspaceId = (string) $workspaceId;

                        TenantContext::run(
                            $tenantId,
                            $workspaceId,
                            fn () => $callback($tenantId, $workspaceId),
                        );
                    }
                });
            });
        }
    }
}
