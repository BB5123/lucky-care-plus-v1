<?php

namespace Tests\Feature\HealthRecord;

use App\Models\User;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * TASK I — `GET /workspaces/{workspace}/profiles` (PRF-T01..T08).
 *
 * The purpose of the endpoint is narrow: give every actor who may read a
 * profile the profile id the health/appointment screens need, without
 * requiring `person.read` (the dashboard family card). Every test therefore
 * goes through HTTP so the middleware stack, the per-profile `Authorizer`
 * batch, and the JSON projection are exercised together.
 *
 * Access is established the way the baseline requires it: ownership is
 * self-only (Matrix B binding decision 2), so an owner who manages a dependent
 * still holds an explicit `profile.basic` grant on that dependent's profile.
 */
class ProfileListTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    private User $owner;

    private User $caregiver;

    private User $viewerWithGrant;

    private User $viewerWithoutGrant;

    private User $otherWorkspaceMember;

    private Person $ownerPerson;

    private Person $dependentPerson;

    private Person $secondDependentPerson;

    private Person $archivedPerson;

    private HealthProfile $ownerProfile;

    private HealthProfile $dependentProfile;

    private HealthProfile $secondDependentProfile;

    private HealthProfile $archivedProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('profile-list-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Profile List Workspace');
        $this->otherWorkspace = $this->createWorkspace($this->tenant, 'Profile List Other Workspace');

        $this->owner = User::factory()->create();
        $this->caregiver = User::factory()->create();
        $this->viewerWithGrant = User::factory()->create();
        $this->viewerWithoutGrant = User::factory()->create();
        $this->otherWorkspaceMember = User::factory()->create();

        $this->addMember($this->workspace, $this->owner, 'profile_owner');
        $this->addMember($this->workspace, $this->caregiver, 'caregiver');
        $this->addMember($this->workspace, $this->viewerWithGrant, 'viewer');
        $this->addMember($this->workspace, $this->viewerWithoutGrant, 'viewer');
        $this->addMember($this->otherWorkspace, $this->otherWorkspaceMember, 'viewer');

        $this->useWorkspace($this->workspace);

        $this->ownerPerson = $this->person('คุณพ่อ', [
            'user_id' => $this->owner->id,
            'birth_date' => '1958-03-04',
        ]);
        $this->ownerProfile = $this->profile($this->ownerPerson);

        // ASCII display names keep the alphabetical assertion independent of
        // the host collation (byte order and ICU agree on "Alice" < "Bob").
        $this->dependentPerson = $this->person('Bob', [
            'managed_by_person_id' => $this->ownerPerson->id,
        ]);
        $this->dependentProfile = $this->profile($this->dependentPerson);

        $this->secondDependentPerson = $this->person('Alice', [
            'managed_by_person_id' => $this->ownerPerson->id,
        ]);
        $this->secondDependentProfile = $this->profile($this->secondDependentPerson);

        $this->archivedPerson = $this->person('Archived Person');
        $this->archivedProfile = $this->profile($this->archivedPerson, [
            'archived_at' => now()->subDay(),
        ]);
    }

    // -- PRF-T01 -------------------------------------------------------------

    public function test_prf_t01_owner_sees_own_profile_first_then_managed_dependents(): void
    {
        // Self-only ownership (Matrix B decision 2) means the owner reaches
        // the dependents through an explicit grant, not through management.
        $this->grant($this->owner, $this->dependentProfile);
        $this->grant($this->owner, $this->secondDependentProfile);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson($this->profilesPath())->assertOk();

        $data = $response->json('data');

        $this->assertCount(3, $data);
        $this->assertSame($this->ownerProfile->id, $data[0]['id']);
        $this->assertSame('self', $data[0]['relationship']);
        $this->assertSame('self', $data[0]['kind']);

        // Dependents, alphabetically by display name.
        $this->assertSame('Alice', $data[1]['display_name']);
        $this->assertSame($this->secondDependentProfile->id, $data[1]['id']);
        $this->assertSame('dependent', $data[1]['relationship']);

        $this->assertSame('Bob', $data[2]['display_name']);
        $this->assertSame($this->dependentProfile->id, $data[2]['id']);
        $this->assertSame('dependent', $data[2]['relationship']);
    }

    // -- PRF-T02 -------------------------------------------------------------

    public function test_prf_t02_caregiver_with_profile_basic_grant_sees_exactly_that_profile(): void
    {
        $this->grant($this->caregiver, $this->dependentProfile);

        Sanctum::actingAs($this->caregiver);

        $response = $this->getJson($this->profilesPath())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->dependentProfile->id)
            ->assertJsonPath('data.0.person_id', $this->dependentPerson->id)
            ->assertJsonPath('data.0.display_name', 'Bob')
            ->assertJsonPath('data.0.relationship', 'other')
            ->assertJsonPath('data.0.kind', 'dependent');
    }

    // -- PRF-T03 -------------------------------------------------------------

    public function test_prf_t03_caregiver_without_a_grant_gets_an_empty_list_not_403(): void
    {
        Sanctum::actingAs($this->caregiver);

        $this->getJson($this->profilesPath())
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    // -- PRF-T04 -------------------------------------------------------------

    public function test_prf_t04_viewer_grant_controls_visibility(): void
    {
        $this->grant($this->viewerWithGrant, $this->dependentProfile);

        Sanctum::actingAs($this->viewerWithGrant);
        $this->getJson($this->profilesPath())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->dependentProfile->id);

        Sanctum::actingAs($this->viewerWithoutGrant);
        $this->getJson($this->profilesPath())
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    // -- PRF-T05 -------------------------------------------------------------

    public function test_prf_t05_member_of_another_workspace_cannot_reach_these_profiles(): void
    {
        Sanctum::actingAs($this->otherWorkspaceMember);

        $this->getJson($this->profilesPath())
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    // -- PRF-T06 -------------------------------------------------------------

    public function test_prf_t06_payload_contains_no_phi_beyond_display_name(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson($this->profilesPath())->assertOk();

        $rows = $response->json('data');

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $keys = array_keys($row);
            sort($keys);

            $this->assertSame(
                ['display_name', 'id', 'kind', 'person_id', 'relationship'],
                $keys,
                'The profile list must expose only the picker projection (N-02).',
            );
        }

        $body = $response->getContent();

        foreach (['birth_date', 'national_id', 'phone', '1958-03-04'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    // -- PRF-T07 -------------------------------------------------------------

    public function test_prf_t07_archived_profile_is_excluded_even_with_a_grant(): void
    {
        $this->grant($this->owner, $this->archivedProfile);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson($this->profilesPath())->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertNotContains($this->archivedProfile->id, $ids);
        $this->assertSame([$this->ownerProfile->id], $ids);
    }

    // -- helpers ------------------------------------------------------------

    private function profilesPath(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/profiles";
    }

    private function useWorkspace(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($this->tenant->id, $workspace->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function person(string $displayName, array $attributes = []): Person
    {
        $this->useWorkspace();

        return Person::forceCreate(array_merge([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => null,
            'display_name' => $displayName,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function profile(Person $person, array $attributes = []): HealthProfile
    {
        $this->useWorkspace();

        return HealthProfile::forceCreate(array_merge([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $person->id,
            'managed_by_person_id' => $person->managed_by_person_id,
            'version' => 1,
        ], $attributes));
    }

    private function grant(User $grantee, HealthProfile $profile): AccessGrant
    {
        $this->useWorkspace();

        return AccessGrant::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'granted_by' => $this->owner->id,
            'grantee_user_id' => $grantee->id,
            'resource_scope' => ['profile.basic'],
            'allowed_actions' => ['read'],
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
}
