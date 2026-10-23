<?php

namespace App\Modules\Timeline\Observers;

use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\Timeline\Application\TimelineSource;

/**
 * Every care-task transition is already append-only in `task_events`
 * (CT-004 / CRM-019), so the timeline subscribes to that table instead of
 * duplicating the task state machine. One event = one timeline entry.
 *
 * `task_events` has no `profile_id`; the owning task supplies it.
 */
final class TaskEventObserver extends TimelineObserver
{
    public function created(TaskEvent $event): void
    {
        $task = $event->task;

        if (! $task instanceof CareTask) {
            return;
        }

        $this->defer(
            (string) $task->tenant_id,
            (string) $task->workspace_id,
            (string) $task->profile_id,
            TimelineSource::forTaskEvent($event, $task),
        );
    }
}
