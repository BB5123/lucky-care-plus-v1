<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\HealthRecord\Domain\HealthRecordNotFoundException;
use App\Modules\HealthRecord\Domain\MeasurementRules;
use App\Modules\HealthRecord\Domain\MeasurementType;
use App\Modules\HealthRecord\Domain\VersionConflictException;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application service for the measurement aggregate.
 *
 * All writes run in a transaction, re-check the optimistic-lock version while
 * holding a row lock, and append a business audit event in the same
 * transaction so the history and the data cannot diverge.
 */
final class HealthMeasurementService
{
    public function __construct(private readonly BusinessAuditLogger $audit) {}

    /**
     * @param  array{type?: ?string, from?: ?string, to?: ?string}  $filters
     */
    public function paginate(HealthProfile $profile, array $filters, int $limit, ?string $cursor): CursorPaginator
    {
        $query = $this->scopedQuery($profile);

        if (isset($filters['type']) && $filters['type'] !== null && $filters['type'] !== '') {
            $this->assertKnownType($filters['type']);
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from']) && $filters['from'] !== null && $filters['from'] !== '') {
            $query->where('recorded_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (isset($filters['to']) && $filters['to'] !== null && $filters['to'] !== '') {
            $query->where('recorded_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    /**
     * Daily buckets for the requested window (default: last 30 days).
     *
     * @return list<array{date: string, min: float, max: float, avg: float, count: int}>
     */
    public function trend(HealthProfile $profile, string $type, Carbon $from, Carbon $to): array
    {
        $this->assertKnownType($type);

        $rows = $this->scopedQuery($profile)
            ->where('type', $type)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<=', $to)
            ->selectRaw("to_char(date_trunc('day', recorded_at), 'YYYY-MM-DD') as bucket_date")
            ->selectRaw('min(value) as min_value, max(value) as max_value, avg(value) as avg_value, count(*) as sample_count')
            ->groupByRaw("date_trunc('day', recorded_at)")
            ->orderByRaw("date_trunc('day', recorded_at)")
            ->get();

        return $rows->map(static fn ($row): array => [
            'date' => (string) $row->bucket_date,
            'min' => round((float) $row->min_value, 2),
            'max' => round((float) $row->max_value, 2),
            'avg' => round((float) $row->avg_value, 2),
            'count' => (int) $row->sample_count,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(HealthProfile $profile, User $actor, array $input): HealthMeasurement
    {
        $normalized = MeasurementRules::forCreate($input);

        return DB::transaction(function () use ($profile, $actor, $normalized): HealthMeasurement {
            $measurement = new HealthMeasurement([
                'tenant_id' => $profile->tenant_id,
                'workspace_id' => $profile->workspace_id,
                'profile_id' => $profile->id,
                'type' => $normalized['type'],
                'value' => $normalized['value'],
                'secondary_value' => $normalized['secondary_value'],
                'unit' => MeasurementType::from($normalized['type'])->unit(),
                'context' => $normalized['context'],
                'note' => $normalized['note'],
                'recorded_at' => $normalized['recorded_at'],
                'recorded_by' => $actor->id,
                'source' => $normalized['source'],
                'version' => 1,
            ]);

            $measurement->save();

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'health_measurement.create',
                'target_type' => 'health_measurement',
                'target_id' => $measurement->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => 1,
                    'source' => $normalized['source'],
                    'note_present' => $normalized['note'] !== null,
                ],
            ]);

            return $measurement;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function correct(
        HealthProfile $profile,
        string $measurementId,
        int $expectedVersion,
        User $actor,
        array $input,
    ): HealthMeasurement {
        return DB::transaction(function () use ($profile, $measurementId, $expectedVersion, $actor, $input): HealthMeasurement {
            $measurement = $this->lockMeasurement($profile, $measurementId);

            if ($measurement->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            $normalized = MeasurementRules::forUpdate($measurement, $input);

            $measurement->fill([
                'value' => $normalized['value'],
                'secondary_value' => $normalized['secondary_value'],
                'context' => $normalized['context'],
                'note' => $normalized['note'],
                'recorded_at' => $normalized['recorded_at'],
                'version' => $measurement->version + 1,
            ]);
            $measurement->save();

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'health_measurement.correct',
                'target_type' => 'health_measurement',
                'target_id' => $measurement->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => $measurement->version,
                    'changed_fields' => array_values(array_intersect(
                        array_keys($input),
                        ['value', 'secondary_value', 'context', 'note', 'recorded_at'],
                    )),
                ],
            ]);

            return $measurement;
        });
    }

    /**
     * Soft delete (DM-R01): the row keeps its history and only the purge job
     * may physically remove it after retention.
     */
    public function delete(HealthProfile $profile, string $measurementId, int $expectedVersion, User $actor): void
    {
        DB::transaction(function () use ($profile, $measurementId, $expectedVersion, $actor): void {
            $measurement = $this->lockMeasurement($profile, $measurementId);

            if ($measurement->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            $measurement->delete();

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'health_measurement.delete',
                'target_type' => 'health_measurement',
                'target_id' => $measurement->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => $measurement->version,
                    'soft_deleted' => true,
                ],
            ]);
        });
    }

    /**
     * @return Builder<HealthMeasurement>
     */
    private function scopedQuery(HealthProfile $profile)
    {
        return HealthMeasurement::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id);
    }

    private function lockMeasurement(HealthProfile $profile, string $measurementId): HealthMeasurement
    {
        $measurement = $this->scopedQuery($profile)
            ->whereKey($measurementId)
            ->lockForUpdate()
            ->first();

        if (! $measurement instanceof HealthMeasurement) {
            throw new HealthRecordNotFoundException;
        }

        return $measurement;
    }

    private function assertKnownType(string $type): void
    {
        if (MeasurementType::tryFrom($type) === null) {
            throw ValidationException::withMessages([
                'type' => ['The measurement type is not supported.'],
            ]);
        }
    }
}
