<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Must be used inside an active TenantContext (RLS on profiles/grants).
 *
 * @extends Factory<AccessGrant>
 */
class AccessGrantFactory extends Factory
{
    protected $model = AccessGrant::class;

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
            'granted_by' => fn () => User::factory()->create()->id,
            'grantee_user_id' => fn () => User::factory()->create()->id,
            'resource_scope' => ['profile.basic', 'vitals', 'medications'],
            'allowed_actions' => ['read', 'create'],
            'purpose' => 'care_coordination',
            'valid_from' => now()->subMinute(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'constraints' => [],
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'valid_from' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => 'factory revoked',
        ]);
    }
}
