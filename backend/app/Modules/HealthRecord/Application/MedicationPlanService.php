<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\HealthRecord\Domain\DomainRuleException;
use App\Modules\HealthRecord\Domain\HealthRecordNotFoundException;
use App\Modules\HealthRecord\Domain\MedicationPlanRules;
use App\Modules\HealthRecord\Domain\VersionConflictException;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application service for medication plans.
 *
 * All writes run in a transaction, re-check the optimistic-lock version while
 * holding a row lock, and append the business audit event in the same
 * transaction. Audit metadata carries identifiers and field names only — never
 * `instructions`, a dosage value, or any other free text (N-02).
 */
final class MedicationPlanService
{
    /** Fields a correction may touch. `status` belongs to `discontinue()`. */
    private const MUTABLE_FIELDS = [
        'name',
        'dosage',
        'form',
        'route',
        'doses_per_day',
        'schedule_times',
        'starts_on',
        'ends_on',
        'prescribed_by',
        'instructions',
    ];

    public function __construct(private readonly BusinessAuditLogger $audit) {}

    public function paginate(
        HealthProfile $profile,
        ?string $status,
        int $limit,
        ?string $cursor,
    ): CursorPaginator {
        $query = $this->scopedQuery($profile);

        if ($status !== null && $status !== '') {
            if (! in_array($status, MedicationPlanRules::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['The medication plan status is not supported.'],
                ]);
            }

            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(HealthProfile $profile, User $actor, array $input): MedicationPlan
    {
        $normalized = MedicationPlanRules::forCreate($input);

        return DB::transaction(function () use ($profile, $actor, $normalized): MedicationPlan {
            $plan = MedicationPlan::query()->create([
                'tenant_id' => $profile->tenant_id,
                'workspace_id' => $profile->workspace_id,
                'profile_id' => $profile->id,
                'recorded_by' => $actor->id,
                'source' => $normalized['source'],
                'name' => $normalized['name'],
                'dosage' => $normalized['dosage'],
                'form' => $normalized['form'],
                'route' => $normalized['route'],
                'doses_per_day' => $normalized['doses_per_day'],
                'schedule_times' => $normalized['schedule_times'],
                'starts_on' => $normalized['starts_on'],
                'ends_on' => $normalized['ends_on'],
                'prescribed_by' => $normalized['prescribed_by'],
                'instructions' => $normalized['instructions'],
                'status' => MedicationPlan::STATUS_ACTIVE,
                'discontinued_reason' => null,
                'discontinued_at' => null,
                'version' => 1,
            ]);

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'medication_plan.create',
                'target_type' => 'medication_plan',
                'target_id' => $plan->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => 1,
                    'status' => $plan->status,
                    'source' => $plan->source,
                ],
            ]);

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        HealthProfile $profile,
        string $planId,
        int $expectedVersion,
        User $actor,
        array $input,
    ): MedicationPlan {
        return DB::transaction(function () use ($profile, $planId, $expectedVersion, $actor, $input): MedicationPlan {
            $plan = $this->lockPlan($profile, $planId);

            if ($plan->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            $normalized = MedicationPlanRules::forUpdate($plan, $input);

            $plan->fill([
                'name' => $normalized['name'],
                'dosage' => $normalized['dosage'],
                'form' => $normalized['form'],
                'route' => $normalized['route'],
                'doses_per_day' => $normalized['doses_per_day'],
                'schedule_times' => $normalized['schedule_times'],
                'starts_on' => $normalized['starts_on'],
                'ends_on' => $normalized['ends_on'],
                'prescribed_by' => $normalized['prescribed_by'],
                'instructions' => $normalized['instructions'],
                'version' => $plan->version + 1,
            ]);
            $plan->save();

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'medication_plan.update',
                'target_type' => 'medication_plan',
                'target_id' => $plan->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => $plan->version,
                    'changed_fields' => array_values(array_intersect(
                        array_keys($input),
                        self::MUTABLE_FIELDS,
                    )),
                ],
            ]);

            return $plan;
        });
    }

    /**
     * Close a plan. A plan may only receive new dose records while active, so
     * this is the transition that freezes its schedule (`DomainRuleException`
     * guards the record path).
     */
    public function discontinue(
        HealthProfile $profile,
        string $planId,
        int $expectedVersion,
        User $actor,
        mixed $reason,
    ): MedicationPlan {
        $normalizedReason = MedicationPlanRules::discontinueReason($reason);

        return DB::transaction(function () use (
            $profile,
            $planId,
            $expectedVersion,
            $actor,
            $normalizedReason,
        ): MedicationPlan {
            $plan = $this->lockPlan($profile, $planId);

            if ($plan->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            if ($plan->isDiscontinued()) {
                throw new DomainRuleException('The medication plan is already discontinued.');
            }

            $plan->fill([
                'status' => MedicationPlan::STATUS_DISCONTINUED,
                'discontinued_reason' => $normalizedReason,
                'discontinued_at' => now(),
                'version' => $plan->version + 1,
            ]);
            $plan->save();

            // The reason itself is free text (N-02): only its presence is audited.
            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'medication_plan.discontinue',
                'target_type' => 'medication_plan',
                'target_id' => $plan->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => $plan->version,
                    'status' => $plan->status,
                    'reason_present' => true,
                ],
            ]);

            return $plan;
        });
    }

    /**
     * @return Builder<MedicationPlan>
     */
    private function scopedQuery(HealthProfile $profile)
    {
        return MedicationPlan::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id);
    }

    private function lockPlan(HealthProfile $profile, string $planId): MedicationPlan
    {
        $plan = $this->scopedQuery($profile)
            ->whereKey($planId)
            ->lockForUpdate()
            ->first();

        if (! $plan instanceof MedicationPlan) {
            throw new HealthRecordNotFoundException;
        }

        return $plan;
    }
}
