<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\HealthRecord\Domain\DomainRuleException;
use App\Modules\HealthRecord\Domain\HealthRecordNotFoundException;
use App\Modules\HealthRecord\Domain\MedicationRecordRules;
use App\Modules\HealthRecord\Domain\VersionConflictException;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\HealthRecord\Models\MedicationRecord;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application service for reported medication events.
 *
 * A `taken` row is self-reported, not clinical proof (`.ai/05 §3`); this service
 * only records what the reporter said. Audit metadata never includes `note`
 * (free text) or the plan's `instructions` (N-02).
 */
final class MedicationRecordService
{
    /** Fields a correction may touch. `plan_id` is immutable. */
    private const MUTABLE_FIELDS = ['status', 'scheduled_for', 'recorded_at', 'note', 'source'];

    public function __construct(private readonly BusinessAuditLogger $audit) {}

    public function paginate(
        HealthProfile $profile,
        ?string $planId,
        ?string $status,
        int $limit,
        ?string $cursor,
    ): CursorPaginator {
        $query = $this->scopedQuery($profile);

        if ($planId !== null && $planId !== '') {
            $query->where('plan_id', $planId);
        }

        if ($status !== null && $status !== '') {
            if (! in_array($status, MedicationRecordRules::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['The medication record status is not supported.'],
                ]);
            }

            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(
        HealthProfile $profile,
        User $actor,
        array $input,
        bool $systemActor = false,
    ): MedicationRecord {
        $normalized = MedicationRecordRules::forCreate($input, $systemActor);

        try {
            return DB::transaction(function () use ($profile, $actor, $normalized): MedicationRecord {
                $plan = $this->lockPlan($profile, $normalized['plan_id']);

                // A discontinued plan cannot receive new events: the schedule
                // is closed and a reported dose would contradict it.
                if ($plan->isDiscontinued()) {
                    throw new DomainRuleException('A discontinued medication plan cannot receive new records.');
                }

                $record = MedicationRecord::query()->create([
                    'tenant_id' => $profile->tenant_id,
                    'workspace_id' => $profile->workspace_id,
                    'profile_id' => $profile->id,
                    'plan_id' => $plan->id,
                    'recorded_by' => $actor->id,
                    'source' => $normalized['source'],
                    'status' => $normalized['status'],
                    'scheduled_for' => $normalized['scheduled_for'],
                    'recorded_at' => $normalized['recorded_at'],
                    'note' => $normalized['note'],
                    'version' => 1,
                ]);

                $this->audit->record([
                    'actor_id' => $actor->id,
                    'actor_type' => 'user',
                    'action' => 'medication_record.create',
                    'target_type' => 'medication_record',
                    'target_id' => $record->id,
                    'result' => 'allow',
                    'metadata' => [
                        'version' => 1,
                        'status' => $record->status,
                        'source' => $record->source,
                        'scheduled_slot' => $record->scheduled_for !== null,
                        'note_present' => $record->note !== null,
                    ],
                ]);

                return $record;
            });
        } catch (QueryException $e) {
            // The partial unique index on (plan_id, scheduled_for) is the last
            // line of defence against double-confirming the same dose slot.
            if ($this->isUniqueViolation($e)) {
                throw new DomainRuleException('This dose slot has already been recorded.');
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function correct(
        HealthProfile $profile,
        string $recordId,
        int $expectedVersion,
        User $actor,
        array $input,
        bool $systemActor = false,
    ): MedicationRecord {
        try {
            return DB::transaction(function () use (
                $profile,
                $recordId,
                $expectedVersion,
                $actor,
                $input,
                $systemActor,
            ): MedicationRecord {
                $record = $this->lockRecord($profile, $recordId);

                if ($record->version !== $expectedVersion) {
                    throw new VersionConflictException;
                }

                $normalized = MedicationRecordRules::forUpdate($record, $input, $systemActor);

                $record->fill([
                    'status' => $normalized['status'],
                    'scheduled_for' => $normalized['scheduled_for'],
                    'recorded_at' => $normalized['recorded_at'],
                    'note' => $normalized['note'],
                    'source' => $normalized['source'],
                    'version' => $record->version + 1,
                ]);
                $record->save();

                $this->audit->record([
                    'actor_id' => $actor->id,
                    'actor_type' => 'user',
                    'action' => 'medication_record.correct',
                    'target_type' => 'medication_record',
                    'target_id' => $record->id,
                    'result' => 'allow',
                    'metadata' => [
                        'version' => $record->version,
                        'changed_fields' => array_values(array_intersect(
                            array_keys($input),
                            self::MUTABLE_FIELDS,
                        )),
                    ],
                ]);

                return $record;
            });
        } catch (QueryException $e) {
            // Moving a correction onto an already-confirmed slot must not
            // surface as a 500: it is the same domain refusal as a double
            // confirmation.
            if ($this->isUniqueViolation($e)) {
                throw new DomainRuleException('This dose slot has already been recorded.');
            }

            throw $e;
        }
    }

    /**
     * @return Builder<MedicationRecord>
     */
    private function scopedQuery(HealthProfile $profile)
    {
        return MedicationRecord::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id);
    }

    private function lockPlan(HealthProfile $profile, string $planId): MedicationPlan
    {
        $plan = MedicationPlan::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->whereKey($planId)
            ->lockForUpdate()
            ->first();

        if (! $plan instanceof MedicationPlan) {
            throw new HealthRecordNotFoundException;
        }

        return $plan;
    }

    private function lockRecord(HealthProfile $profile, string $recordId): MedicationRecord
    {
        $record = $this->scopedQuery($profile)
            ->whereKey($recordId)
            ->lockForUpdate()
            ->first();

        if (! $record instanceof MedicationRecord) {
            throw new HealthRecordNotFoundException;
        }

        return $record;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505' || $e->getCode() === '23505';
    }
}
