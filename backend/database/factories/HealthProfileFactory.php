<?php

namespace Database\Factories;

use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The factory creates the backing person automatically. It must be used
 * inside an active TenantContext, because persons/health_profiles are
 * protected by FORCE RLS.
 *
 * @extends Factory<HealthProfile>
 */
class HealthProfileFactory extends Factory
{
    protected $model = HealthProfile::class;

    public function definition(): array
    {
        $tenantId = TenantContext::tenantId() ?? Uuid7::generate();
        $workspaceId = TenantContext::workspaceId() ?? Uuid7::generate();

        return [
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'person_id' => fn (array $attributes) => Person::forceCreate([
                'id' => Uuid7::generate(),
                'tenant_id' => $attributes['tenant_id'],
                'workspace_id' => $attributes['workspace_id'],
                'user_id' => null,
                'display_name' => 'Factory Person',
                'birth_date' => null,
                'managed_by_person_id' => null,
            ])->id,
            'managed_by_person_id' => null,
            'version' => 1,
        ];
    }
}
