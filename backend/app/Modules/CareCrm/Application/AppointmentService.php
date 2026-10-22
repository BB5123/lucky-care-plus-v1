<?php

namespace App\Modules\CareCrm\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\CareCrm\Domain\AppointmentNotFoundException;
use App\Modules\CareCrm\Domain\AppointmentStateMachine;
use App\Modules\CareCrm\Domain\VersionConflictException;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for the appointment aggregate (`.ai/05 §8`, ADR-004).
 *
 * An appointment is coordination data, not a medical record (APT-001). Every
 * mutation runs in one transaction, proves the client's `If-Match` version
 * while holding the row lock, applies the lifecycle rule through
 * {@see AppointmentStateMachine}, and appends a business audit row.
 *
 * `purpose`/`location` are free text and encrypted at rest. They are never
 * copied into audit metadata or logs (N-02).
 */
final class AppointmentService
{
    /** APT-004 default follow-up policy: remind to record the result in 3 days. */
    private const FOLLOW_UP_DAYS = 3;

    /** Read/write whitelist for PATCH bodies. */
    private const UPDATABLE_FIELDS = [
        'title',
        'provider_name',
        'facility',
        'purpose',
        'location',
        'scheduled_at',
        'duration_minutes',
        'transported_by_task_id',
        'prepared_documents',
    ];

    public function __construct(
        private readonly BusinessAuditLogger $audit,
        private readonly CareTaskService $tasks,
        private readonly AppointmentStateMachine $state,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        HealthProfile $profile,
        array $filters,
        int $limit,
        ?string $cursor,
    ): CursorPaginator {
        $query = Appointment::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id);

        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from']) && $filters['from'] !== null && $filters['from'] !== '') {
            $query->where('scheduled_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (isset($filters['to']) && $filters['to'] !== null && $filters['to'] !== '') {
            $query->where('scheduled_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if (filter_var($filters['upcoming'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            // "Upcoming" means still actionable in the future: a closed or
            // already-attended visit is not upcoming even if a filter is off.
            $query->where('scheduled_at', '>=', now())
                ->whereIn('status', [
                    Appointment::STATUS_PLANNED,
                    Appointment::STATUS_CONFIRMED,
                ]);
        }

        return $query
            ->with(['profile.person'])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    public function find(HealthProfile $profile, string $appointmentId): Appointment
    {
        $appointment = $this->scopedQuery($profile)
            ->with(['profile.person', 'followUpTask'])
            ->whereKey($appointmentId)
            ->first();

        if (! $appointment instanceof Appointment) {
            throw new AppointmentNotFoundException;
        }

        return $appointment;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(HealthProfile $profile, User $actor, array $input): Appointment
    {
        $scheduledAt = Carbon::parse((string) $input['scheduled_at']);

        // A visit cannot be planned in the past; only records that describe an
        // already-closed visit may carry a past timestamp.
        $this->assertScheduledAtAllowed($scheduledAt, Appointment::STATUS_PLANNED);
        $this->assertTransportedTask($profile, $input['transported_by_task_id'] ?? null);

        $rescheduledFromId = $input['rescheduled_from_id'] ?? null;

        if ($rescheduledFromId !== null) {
            $this->assertRescheduleSource($profile, (string) $rescheduledFromId);
        }

        return DB::transaction(function () use ($profile, $actor, $input, $scheduledAt, $rescheduledFromId): Appointment {
            $source = $rescheduledFromId === null
                ? null
                : $this->assertRescheduleSource($profile, (string) $rescheduledFromId, lock: true);

            $appointment = Appointment::query()->create([
                'tenant_id' => $profile->tenant_id,
                'workspace_id' => $profile->workspace_id,
                'profile_id' => $profile->id,
                'created_by' => $actor->id,
                'source' => $input['source'] ?? 'manual',
                'title' => $input['title'],
                'provider_name' => $input['provider_name'] ?? null,
                'facility' => $input['facility'] ?? null,
                'purpose' => $input['purpose'] ?? null,
                'location' => $input['location'] ?? null,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $input['duration_minutes'] ?? null,
                'status' => Appointment::STATUS_PLANNED,
                'cancelled_reason' => null,
                'rescheduled_from_id' => $rescheduledFromId,
                'transported_by_task_id' => $input['transported_by_task_id'] ?? null,
                'prepared_documents' => $this->normalizeDocuments($input['prepared_documents'] ?? []),
                'follow_up_task_id' => null,
                'version' => 1,
            ]);

            if ($source instanceof Appointment) {
                // Rescheduling creates a new row; the old row is marked
                // `rescheduled` instead of being edited in place.
                $this->applyStatus(
                    $source,
                    Appointment::STATUS_RESCHEDULED,
                    $actor,
                    'appointment.rescheduled',
                    ['replacement_appointment_id' => $appointment->id],
                );
            }

            $this->record($actor, 'appointment.create', $appointment, [
                'status' => $appointment->status,
                'version' => $appointment->version,
                'rescheduled_from_id' => $appointment->rescheduled_from_id,
                'document_count' => count($appointment->prepared_documents ?? []),
            ]);

            return $appointment->fresh(['profile.person', 'followUpTask']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        HealthProfile $profile,
        string $appointmentId,
        int $expectedVersion,
        User $actor,
        array $input,
    ): Appointment {
        return DB::transaction(function () use ($profile, $appointmentId, $expectedVersion, $actor, $input): Appointment {
            $appointment = $this->locked($profile, $appointmentId);
            $this->assertVersion($appointment, $expectedVersion);

            if (array_key_exists('transported_by_task_id', $input)) {
                $this->assertTransportedTask($profile, $input['transported_by_task_id']);
            }

            if (array_key_exists('rescheduled_from_id', $input) && $input['rescheduled_from_id'] !== null) {
                $this->assertRescheduleSource(
                    $profile,
                    (string) $input['rescheduled_from_id'],
                    excludeId: $appointment->id,
                );
                $appointment->rescheduled_from_id = (string) $input['rescheduled_from_id'];
            }

            $fromStatus = $appointment->status;
            $targetStatus = $this->targetStatus($appointment, $input);

            if (array_key_exists('scheduled_at', $input) && $input['scheduled_at'] !== null) {
                $scheduledAt = Carbon::parse((string) $input['scheduled_at']);
                $this->assertScheduledAtAllowed($scheduledAt, $targetStatus);
                $appointment->scheduled_at = $scheduledAt;
            }

            foreach (self::UPDATABLE_FIELDS as $field) {
                if ($field === 'scheduled_at' || ! array_key_exists($field, $input)) {
                    continue;
                }

                $appointment->{$field} = $field === 'prepared_documents'
                    ? $this->normalizeDocuments($input[$field] ?? [])
                    : $input[$field];
            }

            if ($targetStatus !== $fromStatus) {
                // Reschedule is the one transition that requires a link to the
                // row it replaces, on the row that records the change.
                if (
                    $targetStatus === Appointment::STATUS_RESCHEDULED
                    && $appointment->rescheduled_from_id === null
                ) {
                    throw ValidationException::withMessages([
                        'rescheduled_from_id' => ['A rescheduled appointment requires the appointment it replaces.'],
                    ]);
                }

                $appointment->status = $targetStatus;
            }

            $appointment->version = $appointment->version + 1;
            $appointment->save();

            $this->record($actor, 'appointment.update', $appointment, [
                'from_status' => $fromStatus,
                'to_status' => $appointment->status,
                'version' => $appointment->version,
                // Field names only: never the values, especially purpose/location.
                'changed_fields' => array_values(array_intersect(
                    array_keys($input),
                    [...self::UPDATABLE_FIELDS, 'status', 'rescheduled_from_id'],
                )),
            ]);

            return $appointment->fresh(['profile.person', 'followUpTask']);
        });
    }

    public function cancel(
        HealthProfile $profile,
        string $appointmentId,
        int $expectedVersion,
        User $actor,
        string $reason,
    ): Appointment {
        return DB::transaction(function () use ($profile, $appointmentId, $expectedVersion, $actor, $reason): Appointment {
            $appointment = $this->locked($profile, $appointmentId);
            $this->assertVersion($appointment, $expectedVersion);

            // Persist the reason in the same write as the status change; the
            // audit row records only that a reason exists, never its text.
            $appointment->cancelled_reason = $reason;

            $this->applyStatus(
                $appointment,
                Appointment::STATUS_CANCELLED,
                $actor,
                'appointment.cancel',
                ['reason_present' => true],
            );

            return $appointment->fresh(['profile.person', 'followUpTask']);
        });
    }

    /**
     * Confirm attendance and spawn exactly one follow-up care task (APT-004).
     *
     * Idempotent by `follow_up_task_id`: a repeated call on an already
     * attended appointment returns the same row without creating a second
     * task (CRM-T06).
     */
    public function attended(
        Workspace $workspace,
        HealthProfile $profile,
        string $appointmentId,
        int $expectedVersion,
        User $actor,
        ?string $attendedAt = null,
    ): Appointment {
        return DB::transaction(function () use (
            $workspace,
            $profile,
            $appointmentId,
            $expectedVersion,
            $actor,
            $attendedAt,
        ): Appointment {
            $appointment = $this->locked($profile, $appointmentId);
            $this->assertVersion($appointment, $expectedVersion);

            if (
                $appointment->status === Appointment::STATUS_ATTENDED
                && $appointment->follow_up_task_id !== null
            ) {
                return $appointment->fresh(['profile.person', 'followUpTask']);
            }

            $this->state->assertAllows($appointment->status, Appointment::STATUS_ATTENDED);

            $occurredAt = $attendedAt === null
                ? now()
                : Carbon::parse($attendedAt);

            $fromStatus = $appointment->status;
            $appointment->status = Appointment::STATUS_ATTENDED;
            $appointment->version = $appointment->version + 1;
            $appointment->save();

            $createdFollowUp = false;

            if ($appointment->follow_up_task_id === null) {
                [$task] = $this->tasks->create(
                    $workspace,
                    $actor,
                    [
                        'profile_id' => $profile->id,
                        'tier' => CareTask::TIER_DELEGATED,
                        'type' => 'FOLLOW_UP',
                        'title' => $this->followUpTitle($appointment->title),
                        'priority' => 'normal',
                        // APT-004 default: remind to record the result in 3 days.
                        'due_at' => $occurredAt->copy()->addDays(self::FOLLOW_UP_DAYS),
                        'timezone' => 'Asia/Bangkok',
                        // Provenance: this task was produced by the appointment
                        // lifecycle, not typed in by the user.
                        'source' => 'appointment',
                    ],
                    null,
                    false,
                );

                $appointment->follow_up_task_id = $task->id;
                $appointment->save();
                $createdFollowUp = true;
            }

            $this->record($actor, 'appointment.attended', $appointment, [
                'from_status' => $fromStatus,
                'to_status' => $appointment->status,
                'version' => $appointment->version,
                'follow_up_task_id' => $appointment->follow_up_task_id,
                'follow_up_created' => $createdFollowUp,
            ]);

            return $appointment->fresh(['profile.person', 'followUpTask']);
        });
    }

    /**
     * Toggle the preparation checklist. Accepts either the full ordered array
     * or a single `{index, done}` patch (APT-005).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateDocuments(
        HealthProfile $profile,
        string $appointmentId,
        int $expectedVersion,
        User $actor,
        array $input,
    ): Appointment {
        return DB::transaction(function () use ($profile, $appointmentId, $expectedVersion, $actor, $input): Appointment {
            $appointment = $this->locked($profile, $appointmentId);
            $this->assertVersion($appointment, $expectedVersion);

            $documents = $appointment->prepared_documents ?? [];
            $index = null;

            if (array_key_exists('documents', $input)) {
                $documents = $this->normalizeDocuments($input['documents'] ?? []);
            } else {
                $index = (int) $input['index'];

                if (! array_key_exists($index, $documents)) {
                    throw ValidationException::withMessages([
                        'index' => ['The selected document item does not exist.'],
                    ]);
                }

                $documents[$index]['done'] = (bool) $input['done'];
            }

            $appointment->prepared_documents = array_values($documents);
            $appointment->version = $appointment->version + 1;
            $appointment->save();

            $this->record($actor, 'appointment.documents', $appointment, [
                'version' => $appointment->version,
                'document_count' => count($appointment->prepared_documents),
                'item_index' => $index,
            ]);

            return $appointment->fresh(['profile.person', 'followUpTask']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function targetStatus(Appointment $appointment, array $input): string
    {
        if (! array_key_exists('status', $input) || $input['status'] === null) {
            return $appointment->status;
        }

        $target = (string) $input['status'];

        // Dedicated actions own the side effects for these statuses; allowing
        // them through PATCH would skip the follow-up task or cancel reason.
        if (in_array($target, [Appointment::STATUS_ATTENDED, Appointment::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Use the dedicated attended or cancel action for this status.'],
            ]);
        }

        $this->state->assertAllows($appointment->status, $target);

        return $target;
    }

    private function applyStatus(
        Appointment $appointment,
        string $toStatus,
        User $actor,
        string $auditAction,
        array $metadata = [],
    ): void {
        $fromStatus = $appointment->status;

        $this->state->assertAllows($fromStatus, $toStatus);

        $appointment->status = $toStatus;
        $appointment->version = $appointment->version + 1;
        $appointment->save();

        $this->record($actor, $auditAction, $appointment, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'version' => $appointment->version,
        ] + $metadata);
    }

    private function locked(HealthProfile $profile, string $appointmentId): Appointment
    {
        $appointment = $this->scopedQuery($profile)
            ->whereKey($appointmentId)
            ->lockForUpdate()
            ->first();

        if (! $appointment instanceof Appointment) {
            throw new AppointmentNotFoundException;
        }

        return $appointment;
    }

    /**
     * @return Builder<Appointment>
     */
    private function scopedQuery(HealthProfile $profile): Builder
    {
        return Appointment::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id);
    }

    private function assertVersion(Appointment $appointment, int $expectedVersion): void
    {
        if ($appointment->version !== $expectedVersion) {
            throw new VersionConflictException;
        }
    }

    private function assertScheduledAtAllowed(Carbon $scheduledAt, string $status): void
    {
        if (
            $scheduledAt->lessThan(now())
            && ! in_array($status, [
                Appointment::STATUS_ATTENDED,
                Appointment::STATUS_MISSED,
                Appointment::STATUS_CANCELLED,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['A future appointment cannot be scheduled in the past.'],
            ]);
        }
    }

    private function assertTransportedTask(HealthProfile $profile, ?string $taskId): void
    {
        if ($taskId === null || $taskId === '') {
            return;
        }

        $exists = CareTask::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->whereKey($taskId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'transported_by_task_id' => ['The transport task must belong to the same profile and workspace.'],
            ]);
        }
    }

    private function assertRescheduleSource(
        HealthProfile $profile,
        string $sourceId,
        ?string $excludeId = null,
        bool $lock = false,
    ): Appointment {
        if ($excludeId !== null && $sourceId === $excludeId) {
            throw ValidationException::withMessages([
                'rescheduled_from_id' => ['An appointment cannot be rescheduled from itself.'],
            ]);
        }

        $query = $this->scopedQuery($profile)->whereKey($sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $source = $query->first();

        if (! $source instanceof Appointment) {
            throw ValidationException::withMessages([
                'rescheduled_from_id' => ['The original appointment must belong to the same profile and workspace.'],
            ]);
        }

        if (! in_array($source->status, [
            Appointment::STATUS_PLANNED,
            Appointment::STATUS_CONFIRMED,
        ], true)) {
            throw ValidationException::withMessages([
                'rescheduled_from_id' => ['Only a planned or confirmed appointment can be rescheduled.'],
            ]);
        }

        return $source;
    }

    /**
     * @param  list<array<string, mixed>>|array<int, array<string, mixed>>  $documents
     * @return list<array{label: string, done: bool}>
     */
    private function normalizeDocuments(array $documents): array
    {
        $normalized = [];

        foreach (array_values($documents) as $document) {
            $normalized[] = [
                'label' => (string) $document['label'],
                'done' => (bool) $document['done'],
            ];
        }

        return $normalized;
    }

    private function followUpTitle(string $title): string
    {
        return Str::limit('ติดตามผลหลังพบแพทย์: '.$title, 160, '');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(User $actor, string $action, Appointment $appointment, array $metadata): void
    {
        $this->audit->record([
            'actor_id' => $actor->id,
            'actor_type' => 'user',
            'action' => $action,
            'target_type' => 'appointment',
            'target_id' => $appointment->id,
            'result' => 'allow',
            'metadata' => $metadata,
        ]);
    }
}
