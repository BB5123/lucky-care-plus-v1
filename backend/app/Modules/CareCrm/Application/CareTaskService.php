<?php

namespace App\Modules\CareCrm\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\Authz\Authorizer;
use App\Modules\Authz\Decision;
use App\Modules\Authz\ResourceRef;
use App\Modules\CareCrm\Domain\CareTaskForbiddenException;
use App\Modules\CareCrm\Domain\CareTaskNotFoundException;
use App\Modules\CareCrm\Events\CareTaskAssigned;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\CareCrm\Models\TaskEvidence;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read/create/evidence use cases for care tasks.
 *
 * State changes live in {@see CareTaskTransitionService}; this service never
 * mutates `status`.
 */
final class CareTaskService
{
    public function __construct(
        private readonly BusinessAuditLogger $audit,
        private readonly Authorizer $authorizer,
        private readonly CareTaskAccess $access,
        private readonly CareTaskEventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        Workspace $workspace,
        User $actor,
        array $filters,
        int $limit,
        ?string $cursor,
    ): CursorPaginator {
        $fullProfileIds = $this->access->fullProfileIds($actor, $workspace);

        $query = CareTask::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            // Scoped, never unscoped: either the actor can read the profile or
            // they must hold an active assignment on this very task.
            ->where(function (Builder $scope) use ($actor, $fullProfileIds): void {
                if ($fullProfileIds !== []) {
                    $scope->whereIn('profile_id', $fullProfileIds)
                        ->orWhereHas('assignments', fn (Builder $assignments) => $assignments
                            ->where('user_id', $actor->id)
                            ->whereNull('released_at'));
                } else {
                    $scope->whereHas('assignments', fn (Builder $assignments) => $assignments
                        ->where('user_id', $actor->id)
                        ->whereNull('released_at'));
                }
            });

        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['tier']) && $filters['tier'] !== null && $filters['tier'] !== '') {
            $query->where('tier', $filters['tier']);
        }

        if (isset($filters['type']) && $filters['type'] !== null && $filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['assignee']) && $filters['assignee'] !== null && $filters['assignee'] !== '') {
            $assigneeId = $filters['assignee'] === 'me'
                ? $actor->id
                : (int) $filters['assignee'];

            $query->whereHas('assignments', fn (Builder $assignments) => $assignments
                ->where('user_id', $assigneeId)
                ->whereNull('released_at'));
        }

        if (isset($filters['due_before']) && $filters['due_before'] !== null && $filters['due_before'] !== '') {
            $query->where('due_at', '<=', Carbon::parse($filters['due_before'])->endOfDay());
        }

        if (filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->overdue();
        }

        return $query
            ->withPhotoEvidenceFlag()
            ->with(['profile.person'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    public function find(Workspace $workspace, string $taskId): CareTask
    {
        $task = CareTask::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->withPhotoEvidenceFlag()
            ->with(['profile.person', 'assignments.user'])
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof CareTask) {
            throw new CareTaskNotFoundException;
        }

        return $task;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: CareTask, 1: bool} [task, created]
     */
    public function create(
        Workspace $workspace,
        User $actor,
        array $input,
        ?string $idempotencyKey = null,
        bool $authorize = true,
        ?string $initialStatus = null,
    ): array {
        $profile = HealthProfile::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->whereKey($input['profile_id'])
            ->first();

        if (! $profile instanceof HealthProfile) {
            throw new CareTaskNotFoundException;
        }

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($workspace, $idempotencyKey);

            if ($existing instanceof CareTask) {
                // System-generated callers (the recurring materialiser) have
                // already scoped the row to this workspace; they do not hold a
                // grant on the profile and must still see an existing
                // occurrence instead of receiving a 404.
                if ($authorize) {
                    $this->assertVisible($existing, $actor);
                }

                return [$existing, false];
            }
        }

        // Creation is a profile-scoped write: the Authorizer proves the actor
        // owns the profile or holds a usable `tasks`/create grant (F-01).
        //
        // Server-generated follow-up tasks (APT-004) are the one exception:
        // the caller has already passed the owning domain permission (e.g.
        // `appointment.update`) and the task is a mandatory side effect, not a
        // client-supplied work item. The row still goes through this service,
        // so it gets its `task_events` and `audit_events` rows.
        if ($authorize) {
            $this->ensureAllowed(
                $this->authorizer->authorize(
                    $actor,
                    'care_task.create',
                    ResourceRef::forProfile($workspace->id, $profile->id),
                ),
            );
        }

        $assigneeId = isset($input['assignee_user_id']) ? (int) $input['assignee_user_id'] : null;

        if ($assigneeId !== null) {
            $this->assertAssigneeIsMember($workspace, $assigneeId);
        }

        $tier = (string) $input['tier'];

        try {
            return DB::transaction(function () use (

                $actor,
                $input,
                $profile,
                $assigneeId,
                $tier,
                $idempotencyKey,
                $initialStatus,
            ): array {
                $status = $initialStatus ?? match ($tier) {
                    CareTask::TIER_ROUTINE => CareTask::STATUS_SCHEDULED,
                    CareTask::TIER_CLINICAL => $assigneeId !== null
                        ? CareTask::STATUS_ASSIGNED
                        : CareTask::STATUS_OPEN,
                    default => CareTask::STATUS_OPEN,
                };

                if (! in_array($status, [
                    CareTask::STATUS_SCHEDULED,
                    CareTask::STATUS_OPEN,
                    CareTask::STATUS_ASSIGNED,
                ], true)) {
                    throw new \InvalidArgumentException('Unsupported initial care-task status.');
                }

                $task = CareTask::query()->create([
                    'tenant_id' => $profile->tenant_id,
                    'workspace_id' => $profile->workspace_id,
                    'profile_id' => $profile->id,
                    'created_by' => $actor->id,
                    'template_id' => $input['template_id'] ?? null,
                    'occurrence_key' => $input['occurrence_key'] ?? null,
                    'tier' => $tier,
                    'type' => $input['type'],
                    'title' => $input['title'],
                    'description' => $input['description'] ?? null,
                    'priority' => $input['priority'] ?? 'normal',
                    'scheduled_for' => $input['scheduled_for'] ?? null,
                    'due_at' => $input['due_at'] ?? null,
                    'timezone' => $input['timezone'] ?? 'Asia/Bangkok',
                    'status' => $status,
                    'outcome' => null,
                    'verification' => CareTask::VERIFICATION_NOT_REQUIRED,
                    // Clinical work always requires a second pair of eyes
                    // (CT-003); other tiers only when explicitly requested.
                    'verification_required' => $tier === CareTask::TIER_CLINICAL
                        ? true
                        : (bool) ($input['verification_required'] ?? false),
                    'evidence_policy' => $input['evidence_policy'] ?? CareTask::EVIDENCE_NONE,
                    'escalation_level' => 0,
                    'blocked_reason' => null,
                    'review_at' => null,
                    'completed_at' => null,
                    'idempotency_key' => $idempotencyKey,
                    'version' => 1,
                    // Provenance: distinguishes a human-created task from a
                    // server-generated side effect (e.g. APT-004 follow-up).
                    'source' => $input['source'] ?? 'manual',
                ]);

                if ($assigneeId !== null) {
                    TaskAssignment::query()->create([
                        'tenant_id' => $task->tenant_id,
                        'workspace_id' => $task->workspace_id,
                        'task_id' => $task->id,
                        'user_id' => $assigneeId,
                        'role' => TaskAssignment::ROLE_PRIMARY,
                        'can_complete' => (bool) ($input['assignee_can_complete'] ?? false),
                        'assigned_by' => $actor->id,
                    ]);
                }

                $task->load(['profile.person', 'assignments.user']);

                $this->events->record(
                    $task,
                    $actor,
                    'created',
                    null,
                    $status,
                    'care_task.create',
                    null,
                    ['tier' => $tier, 'type' => $task->type, 'version' => 1],
                );

                $task = $task->refresh()->loadPhotoEvidenceFlag();

                if ($assigneeId !== null) {
                    // Same transaction as the assignment: the queued job is
                    // written to the same database, so a rollback discards it
                    // together with the row it describes (CRM-017).
                    event(new CareTaskAssigned(
                        taskId: $task->id,
                        tenantId: $task->tenant_id,
                        workspaceId: $task->workspace_id,
                        profileId: $task->profile_id,
                        assigneeUserId: $assigneeId,
                        actorId: $actor->id,
                        tier: $task->tier,
                        dueAt: $task->due_at?->toImmutable(),
                    ));
                }

                return [$task, true];
            });
        } catch (QueryException $exception) {
            // A concurrent request with the same idempotency key won the race.
            if ($this->isUniqueViolation($exception) && $idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($workspace, $idempotencyKey);

                if ($existing instanceof CareTask) {
                    return [$existing, false];
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function addEvidence(
        Workspace $workspace,
        User $actor,
        CareTask $task,
        array $input,
    ): TaskEvidence {
        $this->assertVisible($task, $actor);

        // Assignment does not grant health-record access, but performing the
        // task may require attaching its proof. Reading evidence remains
        // restricted to full-access readers.
        if (! $this->access->canReadFull($actor, $task->profile) && ! $this->access->isAssigned($actor, $task)) {
            throw new CareTaskForbiddenException;
        }

        // A photo is proof of the work, not a free-text claim: it must point
        // at a document that exists in this exact tenant/workspace/profile and
        // is still accepted. The composite FK is the backstop, not the rule.
        $documentId = $input['document_id'] ?? null;

        if (($input['type'] ?? null) === TaskEvidence::TYPE_PHOTO && $documentId === null) {
            throw ValidationException::withMessages([
                'document_id' => ['Photo evidence requires a document.'],
            ]);
        }

        if ($documentId !== null) {
            $this->assertDocumentAttachable($task, (string) $documentId);
        }

        return DB::transaction(function () use ($actor, $task, $input): TaskEvidence {
            $evidence = TaskEvidence::query()->create([
                'tenant_id' => $task->tenant_id,
                'workspace_id' => $task->workspace_id,
                'task_id' => $task->id,
                'type' => $input['type'],
                'metric' => $input['metric'] ?? null,
                'value' => $input['value'] ?? null,
                'unit' => $input['unit'] ?? null,
                'document_id' => $input['document_id'] ?? null,
                'note' => $input['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'care_task.evidence.create',
                'target_type' => 'care_task',
                'target_id' => $task->id,
                'result' => 'allow',
                'metadata' => [
                    'evidence_id' => $evidence->id,
                    'evidence_type' => $evidence->type,
                ],
            ]);

            return $evidence;
        });
    }

    /**
     * @return Collection<int, TaskEvidence>
     */
    public function listEvidence(CareTask $task, User $actor): Collection
    {
        $this->assertEvidenceAccess($task, $actor);

        return $task->evidence()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, TaskEvent>
     */
    public function listEvents(CareTask $task, User $actor): Collection
    {
        $this->assertFullAccess($task, $actor);

        return $task->events()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    public function canReadFull(User $actor, CareTask $task): bool
    {
        return $this->access->canReadFull($actor, $task->profile);
    }

    public function assertVisible(CareTask $task, User $actor): void
    {
        if ($this->canReadFull($actor, $task) || $this->access->isAssigned($actor, $task)) {
            return;
        }

        // A task the actor cannot see is indistinguishable from a missing one.
        throw new CareTaskNotFoundException;
    }

    public function assertFullAccess(CareTask $task, User $actor): void
    {
        if ($this->canReadFull($actor, $task)) {
            return;
        }

        throw new CareTaskForbiddenException;
    }

    /**
     * CR-004: reading evidence is a separate authority from reading the task.
     * Task-read authority (and an assignment) is not enough to disclose the
     * photos, measured values or notes attached as proof.
     */
    public function assertEvidenceAccess(CareTask $task, User $actor): void
    {
        if ($this->access->canReadEvidence($actor, $task->profile)) {
            return;
        }

        throw new CareTaskForbiddenException;
    }

    private function findByIdempotencyKey(Workspace $workspace, string $key): ?CareTask
    {
        return CareTask::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('idempotency_key', $key)
            ->withPhotoEvidenceFlag()
            ->with(['profile.person', 'assignments.user'])
            ->first();
    }

    private function assertDocumentAttachable(CareTask $task, string $documentId): void
    {
        $document = MedicalDocument::query()
            ->where('tenant_id', $task->tenant_id)
            ->where('workspace_id', $task->workspace_id)
            ->where('profile_id', $task->profile_id)
            ->where('status', MedicalDocument::STATUS_AVAILABLE)
            ->whereIn('scan_status', [MedicalDocument::SCAN_CLEAN, MedicalDocument::SCAN_SKIPPED])
            ->whereKey($documentId)
            ->first();

        if (! $document instanceof MedicalDocument) {
            throw ValidationException::withMessages([
                'document_id' => ['The document is not available for this task profile.'],
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
                'assignee_user_id' => ['The assignee must be an active member of this workspace.'],
            ]);
        }
    }

    private function ensureAllowed(Decision $decision): void
    {
        if ($decision->allowed()) {
            return;
        }

        if ($decision->status() === 404) {
            throw new CareTaskNotFoundException;
        }

        throw new CareTaskForbiddenException;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23505';
    }
}
