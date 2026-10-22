<?php

namespace App\Modules\CareCrm\Domain;

use App\Modules\CareCrm\Models\CareTask;

/**
 * ADR-001's three-tier state machine, implemented exactly as specified by
 * `.ai/05-CARE-COORDINATION.md` §2. This class is pure: it only answers whether
 * a transition is legal for a tier. Actor, evidence, idempotency and version
 * checks live in the application services.
 */
final class CareTaskStateMachine
{
    /**
     * @param  string  $action  assign|accept|decline|start|complete|verify|reject|reopen|cancel|block
     * @param  string|null  $outcome  Required only for routine completion
     */
    public function allows(
        CareTask $task,
        string $action,
        ?string $outcome = null,
        bool $system = false,
    ): bool {
        return match ($task->tier) {
            CareTask::TIER_ROUTINE => $this->routineAllows($task, $action, $outcome, $system),
            CareTask::TIER_DELEGATED => $this->delegatedAllows($task, $action),
            CareTask::TIER_CLINICAL => $this->clinicalAllows($task, $action),
            default => false,
        };
    }

    /**
     * @throws DomainRuleException
     */
    public function assertAllows(
        CareTask $task,
        string $action,
        ?string $outcome = null,
        bool $system = false,
    ): void {
        if (! $this->allows($task, $action, $outcome, $system)) {
            throw new DomainRuleException;
        }
    }

    /**
     * routine: scheduled -> completed(done|skipped) | cancelled.
     * `missed` is reserved for the system after grace expires.
     */
    private function routineAllows(CareTask $task, string $action, ?string $outcome, bool $system): bool
    {
        return match ($action) {
            'complete' => $task->status === CareTask::STATUS_SCHEDULED
                && (
                    in_array($outcome, [CareTask::OUTCOME_DONE, CareTask::OUTCOME_SKIPPED], true)
                    || ($system && $outcome === CareTask::OUTCOME_MISSED)
                ),
            'cancel' => $task->status === CareTask::STATUS_SCHEDULED,
            default => false,
        };
    }

    /**
     * delegated: open -> assigned -> accepted -> in_progress -> completed.
     * assigned|accepted may decline back to open; assigned|accepted|in_progress
     * may block; blocked returns to in_progress via `start`; every non-terminal
     * task may be cancelled.
     */
    private function delegatedAllows(CareTask $task, string $action): bool
    {
        return match ($action) {
            'assign' => in_array($task->status, [
                CareTask::STATUS_OPEN,
                CareTask::STATUS_ASSIGNED,
            ], true),
            'accept' => $task->status === CareTask::STATUS_ASSIGNED,
            'decline' => in_array($task->status, [
                CareTask::STATUS_ASSIGNED,
                CareTask::STATUS_ACCEPTED,
            ], true),
            'start' => in_array($task->status, [
                CareTask::STATUS_ACCEPTED,
                CareTask::STATUS_BLOCKED,
            ], true),
            'complete' => $task->status === CareTask::STATUS_IN_PROGRESS,
            'block' => in_array($task->status, [
                CareTask::STATUS_ASSIGNED,
                CareTask::STATUS_ACCEPTED,
                CareTask::STATUS_IN_PROGRESS,
            ], true),
            'cancel' => ! CareTask::isTerminal($task->status),
            'reopen' => $task->status === CareTask::STATUS_COMPLETED,
            default => false,
        };
    }

    /**
     * clinical: assigned -> in_progress -> completed(pending) -> verified or
     * rejected; rejected -> in_progress via reopen. No accept/decline/block.
     */
    private function clinicalAllows(CareTask $task, string $action): bool
    {
        return match ($action) {
            'assign' => in_array($task->status, [
                CareTask::STATUS_OPEN,
                CareTask::STATUS_ASSIGNED,
            ], true),
            'start' => $task->status === CareTask::STATUS_ASSIGNED,
            'complete' => $task->status === CareTask::STATUS_IN_PROGRESS,
            'verify', 'reject' => $task->status === CareTask::STATUS_COMPLETED
                && $task->verification === CareTask::VERIFICATION_PENDING,
            'reopen' => $task->status === CareTask::STATUS_COMPLETED,
            'cancel' => ! CareTask::isTerminal($task->status),
            default => false,
        };
    }
}
