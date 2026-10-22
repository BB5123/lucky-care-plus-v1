<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\HealthRecord\Domain\ConditionRules;
use App\Modules\HealthRecord\Domain\HealthRecordNotFoundException;
use App\Modules\HealthRecord\Domain\VersionConflictException;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthProfile;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application service for permitted conditions.
 */
final class HealthConditionService
{
    public function __construct(private readonly BusinessAuditLogger $audit) {}

    public function paginate(HealthProfile $profile, ?string $status, int $limit, ?string $cursor): CursorPaginator
    {
        $query = $this->scopedQuery($profile);

        if ($status !== null && $status !== '') {
            if (! in_array($status, ConditionRules::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['The condition status is not supported.'],
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
    public function create(HealthProfile $profile, User $actor, array $input): HealthCondition
    {
        $normalized = ConditionRules::forCreate($input);

        return DB::transaction(function () use ($profile, $actor, $normalized): HealthCondition {
            $condition = HealthCondition::query()->create([
                'tenant_id' => $profile->tenant_id,
                'workspace_id' => $profile->workspace_id,
                'profile_id' => $profile->id,
                'recorded_by' => $actor->id,
                'source' => 'manual',
                'code' => $normalized['code'],
                'name' => $normalized['name'],
                'diagnosed_at' => $normalized['diagnosed_at'],
                'status' => $normalized['status'],
                'notes' => $normalized['notes'],
                'version' => 1,
            ]);

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'health_condition.create',
                'target_type' => 'health_condition',
                'target_id' => $condition->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => 1,
                    'has_notes' => $normalized['notes'] !== null,
                ],
            ]);

            return $condition;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function correct(
        HealthProfile $profile,
        string $conditionId,
        int $expectedVersion,
        User $actor,
        array $input,
    ): HealthCondition {
        return DB::transaction(function () use ($profile, $conditionId, $expectedVersion, $actor, $input): HealthCondition {
            $condition = $this->lockCondition($profile, $conditionId);

            if ($condition->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            $normalized = ConditionRules::forUpdate($condition, $input);

            $condition->fill([
                'code' => $normalized['code'],
                'name' => $normalized['name'],
                'diagnosed_at' => $normalized['diagnosed_at'],
                'status' => $normalized['status'],
                'notes' => $normalized['notes'],
                'version' => $condition->version + 1,
            ]);
            $condition->save();

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'health_condition.correct',
                'target_type' => 'health_condition',
                'target_id' => $condition->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => $condition->version,
                    'changed_fields' => array_values(array_intersect(
                        array_keys($input),
                        ['code', 'name', 'diagnosed_at', 'status', 'notes'],
                    )),
                ],
            ]);

            return $condition;
        });
    }

    /**
     * @return Builder<HealthCondition>
     */
    private function scopedQuery(HealthProfile $profile)
    {
        return HealthCondition::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id);
    }

    private function lockCondition(HealthProfile $profile, string $conditionId): HealthCondition
    {
        $condition = $this->scopedQuery($profile)
            ->whereKey($conditionId)
            ->lockForUpdate()
            ->first();

        if (! $condition instanceof HealthCondition) {
            throw new HealthRecordNotFoundException;
        }

        return $condition;
    }
}
