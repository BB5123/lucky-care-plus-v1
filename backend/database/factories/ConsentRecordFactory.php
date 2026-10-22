<?php

namespace Database\Factories;

use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Must be used inside an active TenantContext (RLS on profiles/consents).
 *
 * @extends Factory<ConsentRecord>
 */
class ConsentRecordFactory extends Factory
{
    protected $model = ConsentRecord::class;

    public function definition(): array
    {
        $tenantId = TenantContext::tenantId() ?? Uuid7::generate();
        $workspaceId = TenantContext::workspaceId() ?? Uuid7::generate();

        return [
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'profile_id' => fn (array $attributes) => HealthProfile::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'workspace_id' => $attributes['workspace_id'],
            ])->id,
            'consent_type' => 'health_processing',
            'purpose' => 'care_coordination',
            'scope' => ['profile.basic', 'vitals', 'medications'],
            'policy_version' => 'v1.0.0',
            'consenting_subject_id' => fn (array $attributes) => HealthProfile::query()
                ->findOrFail($attributes['profile_id'])
                ->person_id,
            'legal_basis_type' => 'consent',
            'granted_at' => now(),
            'withdrawn_at' => null,
            'evidence_reference' => null,
            'superseded_by_id' => null,
        ];
    }
}
