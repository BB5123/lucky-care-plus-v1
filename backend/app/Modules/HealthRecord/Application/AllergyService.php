<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\HealthRecord\Domain\AllergyRules;
use App\Modules\HealthRecord\Domain\HealthRecordNotFoundException;
use App\Modules\HealthRecord\Domain\VersionConflictException;
use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\HealthRecord\Models\HealthProfile;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application service for allergies.
 *
 * The frozen catalog has no `allergy.create`, so creation is treated as the
 * first version of an update and is authorized by `allergy.update`
 * (`.ai/07-API-CONTRACT.md §3.3`).
 */
final class AllergyService
{
    public function __construct(private readonly BusinessAuditLogger $audit) {}

    public function paginate(HealthProfile $profile, ?string $severity, int $limit, ?string $cursor): CursorPaginator
    {
        $query = $this->scopedQuery($profile);

        if ($severity !== null && $severity !== '') {
            if (! in_array($severity, AllergyRules::SEVERITIES, true)) {
                throw ValidationException::withMessages([
                    'severity' => ['The allergy severity is not supported.'],
                ]);
            }

            $query->where('severity', $severity);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(HealthProfile $profile, User $actor, array $input): Allergy
    {
        $normalized = AllergyRules::forCreate($input);

        return DB::transaction(function () use ($profile, $actor, $normalized): Allergy {
            $allergy = Allergy::query()->create([
                'tenant_id' => $profile->tenant_id,
                'workspace_id' => $profile->workspace_id,
                'profile_id' => $profile->id,
                'recorded_by' => $actor->id,
                'source' => 'manual',
                'substance' => $normalized['substance'],
                'reaction' => $normalized['reaction'],
                'severity' => $normalized['severity'],
                'noted_at' => $normalized['noted_at'],
                'version' => 1,
            ]);

            // `allergy.update` is the catalog permission for add/edit.
            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'allergy.update',
                'target_type' => 'allergy',
                'target_id' => $allergy->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => 1,
                    'created' => true,
                ],
            ]);

            return $allergy;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        HealthProfile $profile,
        string $allergyId,
        int $expectedVersion,
        User $actor,
        array $input,
    ): Allergy {
        return DB::transaction(function () use ($profile, $allergyId, $expectedVersion, $actor, $input): Allergy {
            $allergy = $this->lockAllergy($profile, $allergyId);

            if ($allergy->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            $normalized = AllergyRules::forUpdate($allergy, $input);

            $allergy->fill([
                'substance' => $normalized['substance'],
                'reaction' => $normalized['reaction'],
                'severity' => $normalized['severity'],
                'noted_at' => $normalized['noted_at'],
                'version' => $allergy->version + 1,
            ]);
            $allergy->save();

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'allergy.update',
                'target_type' => 'allergy',
                'target_id' => $allergy->id,
                'result' => 'allow',
                'metadata' => [
                    'version' => $allergy->version,
                    'created' => false,
                    'changed_fields' => array_values(array_intersect(
                        array_keys($input),
                        ['substance', 'reaction', 'severity', 'noted_at'],
                    )),
                ],
            ]);

            return $allergy;
        });
    }

    /**
     * @return Builder<Allergy>
     */
    private function scopedQuery(HealthProfile $profile)
    {
        return Allergy::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id);
    }

    private function lockAllergy(HealthProfile $profile, string $allergyId): Allergy
    {
        $allergy = $this->scopedQuery($profile)
            ->whereKey($allergyId)
            ->lockForUpdate()
            ->first();

        if (! $allergy instanceof Allergy) {
            throw new HealthRecordNotFoundException;
        }

        return $allergy;
    }
}
