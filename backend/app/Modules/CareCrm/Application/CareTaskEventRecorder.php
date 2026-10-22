<?php

namespace App\Modules\CareCrm\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvent;

/**
 * Writes the two independent histories required by CT-004/CRM-019:
 * one append-only `task_events` row and one `audit_events` row per transition.
 *
 * Free-text reasons are stored on the task event (the domain history the
 * reviewer needs) but never copied into audit metadata, which stays free of
 * health detail (N-02).
 */
final class CareTaskEventRecorder
{
    public function __construct(private readonly BusinessAuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        CareTask $task,
        ?User $actor,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        string $auditAction,
        ?string $reason = null,
        array $metadata = [],
    ): TaskEvent {
        $recorded = TaskEvent::query()->create([
            'tenant_id' => $task->tenant_id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'actor_id' => $actor?->id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event' => $event,
            'reason' => $reason,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);

        $this->audit->record([
            'actor_id' => $actor?->id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'action' => $auditAction,
            'target_type' => 'care_task',
            'target_id' => $task->id,
            'result' => 'allow',
            'metadata' => [
                'event' => $event,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason_present' => $reason !== null && $reason !== '',
            ] + $metadata,
        ]);

        return $recorded;
    }
}
