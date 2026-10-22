<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\Consent\Services\GrantChecker;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * GRANT-*, CONSENT-* and AUDIT-* acceptance tests for the authorization data
 * layer (Phase 0.3a).
 */
class GrantAndConsentTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $workspaceId;

    private User $owner;

    private User $caregiver;

    private Person $ownerPerson;

    private Person $dependentPerson;

    private HealthProfile $dependentProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Uuid7::generate();
        $this->workspaceId = Uuid7::generate();

        // tenants has no RLS; workspaces isolates by tenant only, so an empty
        // workspace id is valid while the workspace row is created.
        TenantContext::set($this->tenantId, '');

        Tenant::forceCreate([
            'id' => $this->tenantId,
            'name' => 'Authz Tenant',
            'slug' => 'authz-tenant',
        ]);
        Workspace::forceCreate([
            'id' => $this->workspaceId,
            'tenant_id' => $this->tenantId,
            'name' => 'Authz Workspace',
            'slug' => 'authz-workspace',
        ]);

        TenantContext::set($this->tenantId, $this->workspaceId);

        $this->owner = User::factory()->create();
        $this->caregiver = User::factory()->create();

        $this->ownerPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->owner->id,
            'display_name' => 'Owner Person',
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

    public function test_grant_t01_active_grant_is_usable_within_scope(): void
    {
        $grant = $this->makeGrant();

        $found = AccessGrant::query()
            ->where('grantee_user_id', $this->caregiver->id)
            ->where('profile_id', $this->dependentProfile->id)
            ->firstOrFail();

        $checker = new GrantChecker;

        $this->assertTrue($checker->isUsable($found, 'profile.basic', 'read'));
        $this->assertTrue($checker->isUsable($found, 'vitals', 'read'));
        $this->assertTrue($checker->isUsable($found, 'medications', 'create'));

        // Outside resource_scope / allowed_actions.
        $this->assertFalse($checker->isUsable($found, 'documents', 'read'));
        $this->assertFalse($checker->isUsable($found, 'vitals', 'export'));

        $this->assertSame(
            ['profile.basic', 'vitals', 'medications'],
            $found->resource_scope,
        );
        $this->assertSame($grant->id, $found->id);
    }

    public function test_grant_t02_expired_grant_is_not_usable(): void
    {
        $grant = $this->makeGrant([
            'status' => 'expired',
            'valid_from' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse((new GrantChecker)->isUsable($grant, 'vitals', 'read'));
    }

    public function test_grant_t03_revoked_grant_is_not_usable(): void
    {
        $grant = $this->makeGrant([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by' => $this->owner->id,
            'revocation_reason' => 'test revocation',
        ]);

        $this->assertFalse((new GrantChecker)->isUsable($grant, 'vitals', 'read'));
    }

    public function test_dm_005_expires_at_must_be_after_valid_from(): void
    {
        $at = now();

        $row = [
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'profile_id' => $this->dependentProfile->id,
            'granted_by' => $this->owner->id,
            'grantee_user_id' => $this->caregiver->id,
            'resource_scope' => json_encode(['vitals']),
            'allowed_actions' => json_encode(['read']),
            'purpose' => 'invalid validity',
            'valid_from' => $at,
            'expires_at' => $at,
            'status' => 'active',
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'constraints' => json_encode([]),
            'created_at' => $at,
            'updated_at' => $at,
        ];

        $this->assertSqlState(
            fn () => DB::table('access_grants')->insert($row),
            '23514',
            'access_grants must reject expires_at <= valid_from',
        );
    }

    public function test_consent_t01_withdrawal_is_allowed_exactly_once(): void
    {
        $consent = $this->makeConsent();

        DB::table('consent_records')
            ->where('id', $consent->id)
            ->update(['withdrawn_at' => now()]);

        $this->assertNotNull(
            DB::table('consent_records')->where('id', $consent->id)->value('withdrawn_at'),
        );

        $this->assertSqlState(
            fn () => DB::table('consent_records')
                ->where('id', $consent->id)
                ->update(['withdrawn_at' => now()->addMinute()]),
            '55000',
            'consent_records must not be withdrawn twice',
        );
    }

    public function test_consent_t02_any_other_column_update_is_rejected(): void
    {
        $consent = $this->makeConsent();

        $this->assertSqlState(
            fn () => DB::table('consent_records')
                ->where('id', $consent->id)
                ->update(['purpose' => 'marketing']),
            '55000',
            'consent_records must be append-only except for withdrawal',
        );

        $this->assertSqlState(
            fn () => DB::table('consent_records')
                ->where('id', $consent->id)
                ->update(['scope' => json_encode(['vitals'])]),
            '55000',
            'consent_records must not allow scope rewrites',
        );
    }

    public function test_audit_t01_logger_links_hashes_and_verifies_chain(): void
    {
        $logger = new SecurityAuditLogger;

        $logger->record([
            'actor_id' => $this->owner->id,
            'actor_type' => 'user',
            'action' => 'access_grant.read',
            'target_type' => 'health_profile',
            'target_id' => $this->dependentProfile->id,
            'result' => 'allow',
            'metadata' => ['scope' => 'vitals', 'count' => 1],
        ]);

        $logger->record([
            'actor_id' => $this->caregiver->id,
            'actor_type' => 'user',
            'action' => 'access_grant.read',
            'target_type' => 'health_profile',
            'target_id' => $this->dependentProfile->id,
            'result' => 'deny',
            'reason_code' => 'scope_not_granted',
            'metadata' => ['scope' => 'documents', 'count' => 2],
        ]);

        $this->assertSame(2, DB::table('security_audit_logs')->count());
        $this->assertTrue($logger->verifyChain());

        $seq = DB::table('security_audit_logs')->orderBy('seq')->pluck('seq')->all();
        $this->assertSame([1, 2], array_map('intval', $seq));
    }

    public function test_audit_t02_security_log_is_append_only(): void
    {
        (new SecurityAuditLogger)->record([
            'actor_id' => $this->owner->id,
            'actor_type' => 'user',
            'action' => 'authz.test',
            'result' => 'allow',
            'metadata' => ['scope' => 'vitals'],
        ]);

        $this->assertSqlState(
            fn () => DB::table('security_audit_logs')->update(['result' => 'deny']),
            '55000',
            'security_audit_logs must reject UPDATE',
        );

        $this->assertSqlState(
            fn () => DB::table('security_audit_logs')->delete(),
            '55000',
            'security_audit_logs must reject DELETE',
        );
    }

    public function test_audit_logger_rejects_phi_keys_in_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SecurityAuditLogger)->record([
            'actor_id' => $this->owner->id,
            'actor_type' => 'user',
            'action' => 'authz.test',
            'result' => 'allow',
            'metadata' => ['name' => 'Somchai'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeGrant(array $overrides = []): AccessGrant
    {
        return AccessGrant::forceCreate(array_merge([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'profile_id' => $this->dependentProfile->id,
            'granted_by' => $this->owner->id,
            'grantee_user_id' => $this->caregiver->id,
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
        ], $overrides));
    }

    private function makeConsent(): ConsentRecord
    {
        return ConsentRecord::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'profile_id' => $this->dependentProfile->id,
            'consent_type' => 'health_processing',
            'purpose' => 'care_coordination',
            'scope' => ['profile.basic', 'vitals'],
            'policy_version' => 'v1.0.0',
            'consenting_subject_id' => $this->dependentPerson->id,
            'legal_basis_type' => 'consent',
            'granted_at' => now(),
            'withdrawn_at' => null,
            'evidence_reference' => 'test',
            'superseded_by_id' => null,
        ]);
    }

    private function assertSqlState(callable $callback, string $expected, string $message): void
    {
        try {
            // A failed PostgreSQL statement aborts the surrounding transaction.
            // Nested DB::transaction() opens a savepoint so the assertions
            // that follow can keep using the connection.
            DB::transaction(fn () => $callback());
        } catch (QueryException $e) {
            $this->assertSame($expected, (string) $e->getCode(), $message.' — got: '.$e->getMessage());

            return;
        }

        $this->fail($message);
    }
}
