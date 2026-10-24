<?php

namespace App\Modules\CareCrm\Application;

use App\Models\User;
use App\Modules\CareCrm\Domain\CareTaskForbiddenException;
use App\Modules\CareCrm\Domain\CareTaskNotFoundException;
use App\Modules\CareCrm\Domain\CareTaskStateMachine;
use App\Modules\CareCrm\Domain\DomainRuleException;
use App\Modules\CareCrm\Domain\VersionConflictException;
use App\Modules\CareCrm\Events\CareTaskAssigned;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for care-task state changes (CT-004).
 *
 * Every public call runs in one transaction that:
 *   1. locks the task row and proves the caller's expected version (AP-007);
 *   2. asks the tier state machine whether the transition exists;
 *   3. applies the tier-specific actor rule (assignee / can_complete /
 *      separation of duties / evidence policy);
 *   4. writes exactly one `task_events` row and one `audit_events` row.
 */
final class CareTaskTransitionService
{
    public function __construct(
        private readonly CareTaskStateMachine $state,
        private readonly CareTaskAccess $access,
        private readonly CareTaskEventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function apply(
        Workspace $workspace,
        string $taskId,
        User $actor,
        string $action,
        int $expectedVersion,
        array $input = [],
    ): CareTask {
        return DB::transaction(function () use (
            $workspace,
            $taskId,
            $actor,
            $action,
            $expectedVersion,
            $input,
        ): CareTask {
            $task = CareTask::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (! $task instanceof CareTask) {
                throw new CareTaskNotFoundException;
            }

            $task->load(['profile.person', 'assignments.user']);

            if ($task->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            match ($action) {
                'assign' => $this->assign($workspace, $task, $actor, $input),
                'accept' => $this->accept($task, $actor),
                'decline' => $this->decline($task, $actor, $input),
                'start' => $this->start($task, $actor),
                'complete' => $this->complete($task, $actor, $input),
                'verify' => $this->verify($task, $actor),
                'reject' => $this->reject($task, $actor, $input),
                'reopen' => $this->reopen($task, $actor, $input),
                'cancel' => $this->cancel($task, $actor, $input),
                'block' => $this->block($task, $actor, $input),
                default => throw new DomainRuleException,
            };

            return $task->fresh(['profile.person', 'assignments.user']);
        });
    }

    /**
     * System reassignment used by the escalation ladder (P2.2).
     *
     * A scheduled job has no acting user and no health-data grant, so it must
     * not impersonate a caregiver to pass the interactive `assign` checks.
     * It still goes through this service — and therefore through the same
     * `task_events` + `audit_events` writer — so the responsibility history
     * (CT-007) and the audit trail remain real. The previous primary is
     * released, never deleted, and the new primary row is written with
     * `assigned_by = null` to mark it as system-originated.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function reassignBySystem(
        Workspace $workspace,
        string $taskId,
        int $targetUserId,
        int $expectedVersion,
        array $metadata = [],
    ): CareTask {
        return DB::transaction(function () use (
            $workspace,
            $taskId,
            $targetUserId,
            $expectedVersion,
            $metadata,
        ): CareTask {
            $task = CareTask::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (! $task instanceof CareTask) {
                throw new CareTaskNotFoundException;
            }

            $task->load(['profile.person', 'assignments.user']);

            if ($task->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            // An active member only; a removed member must never regain work.
            $this->assertAssigneeIsMember($workspace, $targetUserId);

            $from = $task->status;

            $task->assignments()
                ->whereNull('released_at')
                ->where('role', TaskAssignment::ROLE_PRIMARY)
                ->update(['released_at' => now()]);

            TaskAssignment::query()->create([
                'tenant_id' => $task->tenant_id,
                'workspace_id' => $task->workspace_id,
                'task_id' => $task->id,
                'user_id' => $targetUserId,
                'role' => TaskAssignment::ROLE_PRIMARY,
                'can_complete' => false,
                'assigned_by' => null,
            ]);

            // A task that had not been picked up becomes `assigned`; a task
            // already accepted/in progress keeps its workflow state because
            // escalation does not change `status` (ES-003).
            if ($task->status === CareTask::STATUS_OPEN) {
                $task->status = CareTask::STATUS_ASSIGNED;
            }

            $this->commit($task, null, $from, 'assigned', 'care_task.assign', null, [
                'assignee_id' => $targetUserId,
                'role' => TaskAssignment::ROLE_PRIMARY,
                'system' => true,
            ] + $metadata);

            return $task->fresh(['profile.person', 'assignments.user']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assign(Workspace $workspace, CareTask $task, User $actor, array $input): void
    {
        $role = (string) ($input['role'] ?? TaskAssignment::ROLE_PRIMARY);
        $targetId = (int) $input['user_id'];

        if (! $this->access->canManage($actor, $task->profile)) {
            throw new CareTaskForbiddenException;
        }

        $this->assertAssigneeIsMember($workspace, $targetId);

        if ($role === TaskAssignment::ROLE_PRIMARY) {
            $this->state->assertAllows($task, 'assign');

            $hasActivePrimary = $task->assignments()
                ->whereNull('released_at')
                ->where('role', TaskAssignment::ROLE_PRIMARY)
                ->exists();

            if ($hasActivePrimary) {
                // CT-001: a second active primary is a domain conflict. The
                // partial unique index is the backstop for a concurrent race.
                throw new DomainRuleException;
            }

            $from = $task->status;

            try {
                TaskAssignment::query()->create([
                    'tenant_id' => $task->tenant_id,
                    'workspace_id' => $task->workspace_id,
                    'task_id' => $task->id,
                    'user_id' => $targetId,
                    'role' => TaskAssignment::ROLE_PRIMARY,
                    'can_complete' => (bool) ($input['can_complete'] ?? false),
                    'assigned_by' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23505') {
                    throw new DomainRuleException;
                }

                throw $exception;
            }

            $task->status = CareTask::STATUS_ASSIGNED;
            $this->commit($task, $actor, $from, 'assigned', 'care_task.assign', null, [
                'assignee_id' => $targetId,
                'role' => $role,
            ]);

            // Reassignment is a new primary assignment after the previous one
            // was released; both paths notify the new assignee only.
            event(new CareTaskAssigned(
                taskId: $task->id,
                tenantId: $task->tenant_id,
                workspaceId: $task->workspace_id,
                profileId: $task->profile_id,
                assigneeUserId: $targetId,
                actorId: $actor->id,
                tier: $task->tier,
                dueAt: $task->due_at?->toImmutable(),
            ));

            return;
        }

        // Assistants/verifiers may join a task that already has a primary.
        if ($task->status === CareTask::STATUS_OPEN) {
            throw new DomainRuleException;
        }

        $from = $task->status;

        TaskAssignment::query()->create([
            'tenant_id' => $task->tenant_id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'user_id' => $targetId,
            'role' => $role,
            'can_complete' => (bool) ($input['can_complete'] ?? false),
            'assigned_by' => $actor->id,
        ]);

        $this->commit($task, $actor, $from, 'assigned', 'care_task.assign', null, [
            'assignee_id' => $targetId,
            'role' => $role,
        ]);
    }

    private function accept(CareTask $task, User $actor): void
    {
        $this->state->assertAllows($task, 'accept');

        $assignment = $this->activePrimaryAssignment($task, $actor);

        if (! $assignment instanceof TaskAssignment) {
            throw new CareTaskForbiddenException;
        }

        $from = $task->status;
        $assignment->forceFill(['accepted_at' => now()])->save();

        $task->status = CareTask::STATUS_ACCEPTED;
        $this->commit($task, $actor, $from, 'accepted', 'care_task.accept');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function decline(CareTask $task, User $actor, array $input): void
    {
        $this->state->assertAllows($task, 'decline');

        $assignment = $this->activePrimaryAssignment($task, $actor);

        if (! $assignment instanceof TaskAssignment) {
            throw new CareTaskForbiddenException;
        }

        $reason = $this->requiredReason($input, 'reason');

        $from = $task->status;
        $assignment->forceFill([
            'declined_at' => now(),
            'released_at' => now(),
            'decline_reason' => $reason,
        ])->save();

        $task->status = CareTask::STATUS_OPEN;
        $this->commit($task, $actor, $from, 'declined', 'care_task.decline', $reason);
    }

    private function start(CareTask $task, User $actor): void
    {
        $this->state->assertAllows($task, 'start');

        if (! $this->access->mayComplete($actor, $task)) {
            throw new CareTaskForbiddenException;
        }

        $from = $task->status;
        $wasBlocked = $task->status === CareTask::STATUS_BLOCKED;

        $task->status = CareTask::STATUS_IN_PROGRESS;
        $task->blocked_reason = null;
        $task->review_at = null;

        $this->commit(
            $task,
            $actor,
            $from,
            $wasBlocked ? 'unblocked' : 'started',
            'care_task.start',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function complete(CareTask $task, User $actor, array $input): void
    {
        $outcome = $input['outcome'] ?? null;

        // CT: routine completion must record whether the routine was done or
        // deliberately skipped (422 before the state machine is consulted).
        if ($task->tier === CareTask::TIER_ROUTINE && $outcome === null) {
            throw ValidationException::withMessages([
                'outcome' => ['A routine task must record an outcome (done or skipped).'],
            ]);
        }

        $this->state->assertAllows($task, 'complete', $outcome);

        if ($task->tier === CareTask::TIER_ROUTINE) {
            // routine work has no accept/start step: the assignee (or someone
            // with full task access) confirms it in one call.
            if (
                ! $this->access->canReadFull($actor, $task->profile)
                && ! $this->access->isAssigned($actor, $task)
            ) {
                throw new CareTaskForbiddenException;
            }
        } elseif (! $this->access->mayComplete($actor, $task)) {
            // CT-002/CT-T04: only the primary (or an explicit can_complete
            // helper) may complete delegated/clinical work.
            throw new CareTaskForbiddenException;
        }

        if ($task->tier === CareTask::TIER_CLINICAL) {
            $this->assertRequiredEvidence($task);
        }

        $from = $task->status;

        $task->status = CareTask::STATUS_COMPLETED;
        $task->outcome = $outcome ?? CareTask::OUTCOME_DONE;
        $task->completed_at = now();
        $task->verification = $task->tier === CareTask::TIER_CLINICAL
            ? CareTask::VERIFICATION_PENDING
            : CareTask::VERIFICATION_NOT_REQUIRED;
        $task->blocked_reason = null;
        $task->review_at = null;

        $this->commit($task, $actor, $from, 'completed', 'care_task.complete', null, [
            'outcome' => $task->outcome,
            'evidence_policy' => $task->evidence_policy,
        ]);
    }

    private function verify(CareTask $task, User $actor): void
    {
        $this->state->assertAllows($task, 'verify');
        $this->assertSeparateFromCompleter($task, $actor);

        $from = $task->status;
        $task->verification = CareTask::VERIFICATION_VERIFIED;

        $this->commit($task, $actor, $from, 'verified', 'care_task.verify');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function reject(CareTask $task, User $actor, array $input): void
    {
        $this->state->assertAllows($task, 'reject');
        $this->assertSeparateFromCompleter($task, $actor);

        $reason = $this->requiredReason($input, 'reason');

        $from = $task->status;
        $task->verification = CareTask::VERIFICATION_REJECTED;

        $this->commit($task, $actor, $from, 'rejected', 'care_task.reject', $reason);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function reopen(CareTask $task, User $actor, array $input): void
    {
        $this->state->assertAllows($task, 'reopen');

        $reason = $this->requiredReason($input, 'reason');

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => ['A reopen reason of at least 5 characters is required.'],
            ]);
        }

        $from = $task->status;
        $task->status = CareTask::STATUS_IN_PROGRESS;
        $task->completed_at = null;
        $task->outcome = null;
        $task->verification = CareTask::VERIFICATION_NOT_REQUIRED;
        $task->blocked_reason = null;
        $task->review_at = null;

        $this->commit($task, $actor, $from, 'reopened', 'care_task.reopen', $reason);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function cancel(CareTask $task, User $actor, array $input): void
    {
        $this->state->assertAllows($task, 'cancel');

        if (! $this->access->canManage($actor, $task->profile)) {
            throw new CareTaskForbiddenException;
        }

        $reason = $this->requiredReason($input, 'reason');

        $from = $task->status;
        $task->status = CareTask::STATUS_CANCELLED;
        $task->review_at = null;

        // Responsibility history is kept, but no assignment remains active on
        // a task that can never be worked again.
        $task->assignments()
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        $this->commit($task, $actor, $from, 'cancelled', 'care_task.cancel', $reason);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function block(CareTask $task, User $actor, array $input): void
    {
        $this->state->assertAllows($task, 'block');

        if (! $this->access->mayComplete($actor, $task)) {
            throw new CareTaskForbiddenException;
        }

        $reason = $this->requiredReason($input, 'blocked_reason');

        $from = $task->status;
        $task->status = CareTask::STATUS_BLOCKED;
        $task->blocked_reason = $reason;
        $task->review_at = $input['review_at'];

        $this->commit($task, $actor, $from, 'blocked', 'care_task.block', $reason);
    }

    /**
     * Writes the task row, then the two histories. The version only advances
     * on a successful transition.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function commit(
        CareTask $task,
        ?User $actor,
        string $fromStatus,
        string $event,
        string $auditAction,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $task->version = $task->version + 1;
        $task->save();

        $this->events->record(
            $task,
            $actor,
            $event,
            $fromStatus,
            $task->status,
            $auditAction,
            $reason,
            ['version' => $task->version] + $metadata,
        );
    }

    private function activePrimaryAssignment(CareTask $task, User $actor): ?TaskAssignment
    {
        return $task->assignments()
            ->where('user_id', $actor->id)
            ->where('role', TaskAssignment::ROLE_PRIMARY)
            ->whereNull('released_at')
            ->first();
    }

    private function assertSeparateFromCompleter(CareTask $task, User $actor): void
    {
        $completedBy = $task->events()
            ->where('event', 'completed')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('actor_id');

        if ($completedBy !== null && (int) $completedBy === $actor->id) {
            // CT-003 / CRM-007: separation of duties.
            throw new CareTaskForbiddenException;
        }
    }

    private function assertRequiredEvidence(CareTask $task): void
    {
        $policy = $task->evidence_policy;

        if ($policy === CareTask::EVIDENCE_NONE) {
            return;
        }

        $hasValue = $task->evidence()->where('type', 'value')->exists();
        // A photo row must be backed by a document that still exists in this
        // tenant/workspace/profile and is accepted; a bare `photo` label with a
        // null document_id is not evidence (CT-005).
        $hasPhoto = $task->evidence()
            ->where('type', 'photo')
            ->whereNotNull('document_id')
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('medical_documents')
                    ->whereColumn('medical_documents.id', 'task_evidence.document_id')
                    ->where('medical_documents.status', MedicalDocument::STATUS_AVAILABLE)
                    ->whereNull('medical_documents.deleted_at');
            })
            ->exists();

        $satisfied = match ($policy) {
            CareTask::EVIDENCE_VALUE => $hasValue,
            CareTask::EVIDENCE_PHOTO => $hasPhoto,
            CareTask::EVIDENCE_BOTH => $hasValue && $hasPhoto,
            default => true,
        };

        if (! $satisfied) {
            throw ValidationException::withMessages([
                'evidence' => ['Evidence matching the task policy is required before completion.'],
            ]);
        }
    }

    private function assertAssigneeIsMember(Workspace $workspace, int $userId): void
    {
        $exists = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'user_id' => ['The assignee must be an active member of this workspace.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requiredReason(array $input, string $field): string
    {
        $reason = trim((string) ($input[$field] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                $field => ['A reason is required for this action.'],
            ]);
        }

        return $reason;
    }
}
