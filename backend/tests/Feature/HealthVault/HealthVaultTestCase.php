<?php

namespace Tests\Feature\HealthVault;

use App\Models\User;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * Fixture base for the P1.1 Health Vault acceptance tests.
 *
 * Two workspaces under one tenant prove cross-workspace isolation; three
 * personas (owner, caregiver, viewer) cover the role/scope combinations in
 * the frozen matrices. All RLS-protected fixture writes happen inside the
 * test transaction with an explicit server-side tenant context.
 */
abstract class HealthVaultTestCase extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Workspace $workspace;

    protected Workspace $otherWorkspace;

    protected User $owner;

    protected User $caregiver;

    protected User $viewer;

    protected HealthProfile $profile;

    protected HealthProfile $sameWorkspaceOtherProfile;

    protected HealthProfile $otherWorkspaceProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('hv-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'HV Workspace');
        $this->otherWorkspace = $this->createWorkspace($this->tenant, 'HV Other Workspace');

        $this->owner = User::factory()->create();
        $this->caregiver = User::factory()->create();
        $this->viewer = User::factory()->create();

        $this->addMember($this->workspace, $this->owner, 'profile_owner');
        $this->addMember($this->workspace, $this->caregiver, 'caregiver');
        $this->addMember($this->workspace, $this->viewer, 'viewer');

        $this->useWorkspace($this->workspace);

        $ownerPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'display_name' => 'Owner Person',
        ]);

        $this->profile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $ownerPerson->id,
            'version' => 1,
        ]);

        $dependentPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => null,
            'display_name' => 'Dependent Person',
            'managed_by_person_id' => $ownerPerson->id,
        ]);

        $this->sameWorkspaceOtherProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $dependentPerson->id,
            'managed_by_person_id' => $ownerPerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->otherWorkspace);

        $otherWorkspacePerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'user_id' => null,
            'display_name' => 'Other Workspace Person',
        ]);

        $this->otherWorkspaceProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'person_id' => $otherWorkspacePerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->workspace);
    }

    protected function useWorkspace(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($this->tenant->id, $workspace->id);
    }

    protected function profilesPath(?HealthProfile $profile = null, ?Workspace $workspace = null): string
    {
        $profile ??= $this->profile;
        $workspace ??= $this->workspace;

        return "/api/v1/workspaces/{$workspace->id}/profiles/{$profile->id}";
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $actions
     */
    protected function grant(
        User $grantee,
        array $scopes,
        array $actions,
        ?HealthProfile $profile = null,
    ): AccessGrant {
        $profile ??= $this->profile;
        $this->useWorkspace();

        return AccessGrant::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $profile->id,
            'granted_by' => $this->owner->id,
            'grantee_user_id' => $grantee->id,
            'resource_scope' => $scopes,
            'allowed_actions' => $actions,
            'purpose' => 'care_coordination',
            'valid_from' => now()->subMinute(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'constraints' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeMeasurement(array $attributes = [], ?HealthProfile $profile = null): HealthMeasurement
    {
        $profile ??= $this->profile;

        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return HealthMeasurement::forceCreate(array_merge([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'type' => 'heart_rate',
            'value' => 72,
            'unit' => 'bpm',
            'recorded_at' => now()->subDay(),
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'version' => 1,
        ], $attributes));
    }
}
