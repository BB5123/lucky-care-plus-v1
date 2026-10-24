<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Modules\Authz\Authorizer;
use App\Modules\Authz\PermissionCatalog;
use App\Modules\Authz\ResourceRef;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Modules\Workspace\Services\WorkspaceResolver;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * AUTH-*, GRANT-T04/T05, CONSENT-T03/T04, FAIL-T01, AUDIT-T03.
 */
class AuthorizerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $workspaceId;

    private User $owner;

    private User $caregiver;

    private User $outsider;

    private Person $ownerPerson;

    private HealthProfile $ownerProfile;

    private Person $dependentPerson;

    private HealthProfile $dependentProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Uuid7::generate();
        $this->workspaceId = Uuid7::generate();

        TenantContext::set($this->tenantId, '');

        Tenant::forceCreate([
            'id' => $this->tenantId,
            'name' => 'Authorizer Tenant',
            'slug' => 'authorizer-tenant',
        ]);

        Workspace::forceCreate([
            'id' => $this->workspaceId,
            'tenant_id' => $this->tenantId,
            'name' => 'Authorizer Workspace',
            'slug' => 'authorizer-workspace',
        ]);

        TenantContext::set($this->tenantId, $this->workspaceId);

        $this->owner = User::factory()->create();
        $this->caregiver = User::factory()->create();
        $this->outsider = User::factory()->create();

        WorkspaceMembership::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->owner->id,
            'role' => 'profile_owner',
            'status' => 'active',
        ]);

        WorkspaceMembership::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->caregiver->id,
            'role' => 'caregiver',
            'status' => 'active',
        ]);

        $this->ownerPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->owner->id,
            'display_name' => 'Owner Person',
        ]);

        $this->ownerProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'person_id' => $this->ownerPerson->id,
            'managed_by_person_id' => null,
            'version' => 1,
        ]);

        $this->dependentPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => null,
            'display_name' => 'Dependent Person',
            'managed_by_person_id' => $this->ownerPerson->id,
        ]);

        $this->dependentProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'person_id' => $this->dependentPerson->id,
            'managed_by_person_id' => $this->ownerPerson->id,
            'version' => 1,
        ]);
    }

    private function authorizer(): Authorizer
    {
        return $this->app->make(Authorizer::class);
    }

    public function test_auth_001_unknown_permission_is_a_server_error(): void
    {
        $decision = $this->authorizer()->authorize(
            $this->owner,
            'does.not.exist',
            ResourceRef::forProfile($this->workspaceId, $this->ownerProfile->id),
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame('unknown_permission', $decision->code());
        $this->assertSame(500, $decision->status());
    }

    public function test_auth_002_unauthenticated_is_401(): void
    {
        $decision = $this->authorizer()->authorize(
            null,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->ownerProfile->id),
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame('unauthenticated', $decision->code());
        $this->assertSame(401, $decision->status());
    }

    public function test_auth_003_non_member_gets_404_without_leaking_existence(): void
    {
        $decision = $this->authorizer()->authorize(
            $this->outsider,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->dependentProfile->id),
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame('out_of_scope', $decision->code());
        $this->assertSame(404, $decision->status());
    }

    public function test_auth_004_member_without_grant_and_not_owner_is_forbidden(): void
    {
        $decision = $this->authorizer()->authorize(
            $this->caregiver,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->dependentProfile->id),
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame('forbidden', $decision->code());
        $this->assertSame(403, $decision->status());
    }

    public function test_auth_005_every_catalog_permission_has_a_scope_action_mapping(): void
    {
        $catalog = new PermissionCatalog;
        $authorizer = $this->authorizer();

        $this->assertCount(99, $catalog->all());

        foreach ($catalog->all() as $permission) {
            $mapping = $authorizer->mappingFor($permission);

            $this->assertNotNull($mapping, "{$permission} has no scope/action mapping");
            $this->assertArrayHasKey('scope', $mapping);
            $this->assertArrayHasKey('action', $mapping);
            $this->assertTrue(
                $catalog->allowsAction($mapping['action']),
                "{$permission} maps to unknown action {$mapping['action']}",
            );

            if ($mapping['scope'] !== null) {
                $this->assertContains(
                    $mapping['scope'],
                    $catalog->scopes(),
                    "{$permission} maps to unknown scope {$mapping['scope']}",
                );
            }
        }
    }

    public function test_grant_t04_active_grant_covering_scope_and_action_allows(): void
    {
        $this->makeGrant(['vitals'], ['read']);

        $decision = $this->authorizer()->authorize(
            $this->caregiver,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->dependentProfile->id),
        );

        $this->assertTrue($decision->allowed(), $decision->code());
        $this->assertSame('allowed', $decision->code());
        $this->assertSame(200, $decision->status());
    }

    public function test_grant_t05_grant_without_the_action_denies(): void
    {
        // Read-only grant, update action requested.
        $this->makeGrant(['vitals'], ['read']);

        $decision = $this->authorizer()->authorize(
            $this->caregiver,
            'health_measurement.correct',
            ResourceRef::forProfile($this->workspaceId, $this->dependentProfile->id),
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame('forbidden', $decision->code());
        $this->assertSame(403, $decision->status());
    }

    public function test_consent_t03_ai_context_without_purpose_denies(): void
    {
        $decision = $this->authorizer()->authorize(
            $this->owner,
            'ai.health_context.use',
            ResourceRef::forProfile($this->workspaceId, $this->ownerProfile->id),
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame('no_consent', $decision->code());
        $this->assertSame(403, $decision->status());
    }

    public function test_consent_t04_active_ai_processing_consent_allows(): void
    {
        ConsentRecord::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'profile_id' => $this->ownerProfile->id,
            'consent_type' => 'ai_processing',
            'purpose' => 'ai_processing',
            'scope' => ['ai_context'],
            'policy_version' => 'v1.1.0',
            'consenting_subject_id' => $this->ownerPerson->id,
            'legal_basis_type' => 'consent',
            'granted_at' => now(),
            'withdrawn_at' => null,
            'evidence_reference' => 'test',
            'superseded_by_id' => null,
        ]);

        $decision = $this->authorizer()->authorize(
            $this->owner,
            'ai.health_context.use',
            ResourceRef::forProfile($this->workspaceId, $this->ownerProfile->id),
            'ai_processing',
        );

        $this->assertTrue($decision->allowed(), $decision->code());
    }

    public function test_fail_t01_a_throwing_dependency_fails_closed_without_side_effects(): void
    {
        $this->app->instance(WorkspaceResolver::class, new class extends WorkspaceResolver
        {
            public function resolve(string $workspaceId): ?Workspace
            {
                throw new RuntimeException('forced authorization dependency failure');
            }
        });

        $before = DB::table('security_audit_logs')->count();

        $decision = $this->app->make(Authorizer::class)->authorize(
            $this->owner,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->ownerProfile->id),
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame('authorization_unavailable', $decision->code());
        $this->assertSame(503, $decision->status());
        $this->assertSame($before, DB::table('security_audit_logs')->count(), 'a failed pipeline must not write a decision');
    }

    public function test_audit_t03_every_completed_decision_writes_exactly_one_row_with_the_right_result(): void
    {
        $before = DB::table('security_audit_logs')->count();

        $allow = $this->authorizer()->authorize(
            $this->owner,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->ownerProfile->id),
        );

        $this->assertTrue($allow->allowed());
        $this->assertSame($before + 1, DB::table('security_audit_logs')->count());

        $allowRow = DB::table('security_audit_logs')->orderByDesc('seq')->first();
        $this->assertSame('allow', $allowRow->result);
        $this->assertSame('health_measurement.read', $allowRow->action);
        $this->assertSame($this->owner->id, (int) $allowRow->actor_id);
        $this->assertSame($this->ownerProfile->id, $allowRow->target_id);

        $deny = $this->authorizer()->authorize(
            $this->caregiver,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->dependentProfile->id),
        );

        $this->assertFalse($deny->allowed());
        $this->assertSame($before + 2, DB::table('security_audit_logs')->count());

        $denyRow = DB::table('security_audit_logs')->orderByDesc('seq')->first();
        $this->assertSame('deny', $denyRow->result);
        $this->assertSame('forbidden', $denyRow->reason_code);
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $actions
     */
    private function makeGrant(array $scopes, array $actions): AccessGrant
    {
        return AccessGrant::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'profile_id' => $this->dependentProfile->id,
            'granted_by' => $this->owner->id,
            'grantee_user_id' => $this->caregiver->id,
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
}
