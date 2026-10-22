<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TEN-T01..T05 — tenant/workspace isolation.
 *
 * The application connects with a NOBYPASSRLS role and every tenant-scoped
 * table is FORCE ROW LEVEL SECURITY, so the connection used by the tests is
 * subject to the same policies as production. Fixtures are therefore created
 * with an explicit TenantContext, exactly like application code must do, and
 * the assertions run under the context they are meant to exercise.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $workspaceId;

    private string $otherWorkspaceId;

    private string $foreignTenantId;

    private string $foreignWorkspaceId;

    private string $personId;

    private string $otherPersonId;

    private string $foreignPersonId;

    private string $healthProfileId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Uuid7::generate();
        $this->workspaceId = Uuid7::generate();
        $this->otherWorkspaceId = Uuid7::generate();
        $this->foreignTenantId = Uuid7::generate();
        $this->foreignWorkspaceId = Uuid7::generate();
        $this->personId = Uuid7::generate();
        $this->otherPersonId = Uuid7::generate();
        $this->foreignPersonId = Uuid7::generate();

        // -- Step 1: tenants and workspaces ---------------------------------
        // `tenants` carries no RLS. The `workspaces` policy isolates by
        // tenant_id only, so the workspace part of the context is not used and
        // an empty string is a valid value while workspaces are created.
        TenantContext::set($this->tenantId, '');

        Tenant::forceCreate([
            'id' => $this->tenantId,
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
        ]);
        Workspace::forceCreate([
            'id' => $this->workspaceId,
            'tenant_id' => $this->tenantId,
            'name' => 'Workspace A1',
            'slug' => 'workspace-a1',
        ]);
        Workspace::forceCreate([
            'id' => $this->otherWorkspaceId,
            'tenant_id' => $this->tenantId,
            'name' => 'Workspace A2',
            'slug' => 'workspace-a2',
        ]);

        // The foreign tenant owns its own context block; run() restores the
        // tenant A context before returning.
        TenantContext::run($this->foreignTenantId, '', function () {
            Tenant::forceCreate([
                'id' => $this->foreignTenantId,
                'name' => 'Tenant B',
                'slug' => 'tenant-b',
            ]);
            Workspace::forceCreate([
                'id' => $this->foreignWorkspaceId,
                'tenant_id' => $this->foreignTenantId,
                'name' => 'Workspace B1',
                'slug' => 'workspace-b1',
            ]);
        });

        // -- Step 2: workspace-scoped fixtures ------------------------------
        $this->user = User::factory()->create();

        TenantContext::set($this->tenantId, $this->workspaceId);

        $now = now();

        Person::forceCreate([
            'id' => $this->personId,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'display_name' => 'Person A1',
        ]);

        DB::table('workspace_memberships')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->user->id,
            'role' => 'caregiver',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('invitations')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'email' => 'invitee@example.test',
            'role' => 'viewer',
            'token' => Uuid7::generate(),
            'expires_at' => $now->copy()->addDay(),
            'accepted_at' => null,
            'created_by' => $this->user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_points')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'person_id' => $this->personId,
            'type' => 'phone',
            'value' => '0800000000',
            'is_primary' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('person_relationships')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'from_person_id' => $this->personId,
            'to_person_id' => $this->personId,
            'kinship' => 'self',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // -- Phase 0.3a authorization tables ---------------------------------
        // Fixtures exist so TEN-T02 proves the new RLS policies deny reads
        // rather than merely counting empty tables.
        $grantee = User::factory()->create();
        $this->healthProfileId = Uuid7::generate();

        DB::table('health_profiles')->insert([
            'id' => $this->healthProfileId,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'person_id' => $this->personId,
            'managed_by_person_id' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('care_relationships')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'profile_id' => $this->healthProfileId,
            'person_id' => $this->personId,
            'ring' => 'inner',
            'relationship_type' => 'self',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('delegations')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'represented_person_id' => $this->personId,
            'representative_user_id' => $this->user->id,
            'level' => 'L1',
            'evidence_document_id' => null,
            'verified_by' => null,
            'verified_at' => null,
            'expires_at' => $now->copy()->addYear(),
            'status' => 'pending',
            'revoked_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('access_grants')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'profile_id' => $this->healthProfileId,
            'granted_by' => $this->user->id,
            'grantee_user_id' => $grantee->id,
            'resource_scope' => json_encode(['profile.basic']),
            'allowed_actions' => json_encode(['read']),
            'purpose' => 'tenancy-fixture',
            'valid_from' => $now->copy()->subMinute(),
            'expires_at' => $now->copy()->addYear(),
            'status' => 'active',
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'constraints' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('consent_records')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'profile_id' => $this->healthProfileId,
            'consent_type' => 'health_processing',
            'purpose' => 'care_coordination',
            'scope' => json_encode(['profile.basic']),
            'policy_version' => 'v1.0.0',
            'consenting_subject_id' => $this->personId,
            'legal_basis_type' => 'consent',
            'granted_at' => $now,
            'withdrawn_at' => null,
            'evidence_reference' => null,
            'superseded_by_id' => null,
            'created_at' => $now,
        ]);

        DB::table('audit_events')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'tenancy.fixture',
            'target_type' => null,
            'target_id' => null,
            'result' => 'allow',
            'reason_code' => null,
            'metadata' => json_encode([]),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('security_audit_logs')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'occurred_at' => $now,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'tenancy.fixture',
            'target_type' => null,
            'target_id' => null,
            'result' => 'allow',
            'reason_code' => null,
            'workspace_id' => $this->workspaceId,
            'metadata' => json_encode([]),
            'prev_hash' => null,
            'hash' => DB::raw("decode('00', 'hex')"),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Second workspace of the same tenant: its own context block.
        TenantContext::run($this->tenantId, $this->otherWorkspaceId, function () {
            Person::forceCreate([
                'id' => $this->otherPersonId,
                'tenant_id' => $this->tenantId,
                'workspace_id' => $this->otherWorkspaceId,
                'display_name' => 'Person A2',
            ]);
        });

        // Foreign tenant/workspace: its own context block as well.
        TenantContext::run($this->foreignTenantId, $this->foreignWorkspaceId, function () {
            Person::forceCreate([
                'id' => $this->foreignPersonId,
                'tenant_id' => $this->foreignTenantId,
                'workspace_id' => $this->foreignWorkspaceId,
                'display_name' => 'Person B1',
            ]);
        });
    }

    /**
     * TEN-T01: the composite FK rejects a tenant_id that does not match the
     * tenant that owns workspace_id.
     *
     * RLS is satisfied for the attempted row (the session context is set to the
     * row's own tenant/workspace pair) so the only remaining barrier is the
     * composite foreign key to workspaces(id, tenant_id): the failure must be
     * foreign_key_violation (23503), not a row-level security error.
     */
    public function test_ten_t01_tenant_scoped_tables_reject_mismatched_tenant_id(): void
    {
        $now = now();
        $otherUser = User::factory()->create();

        $attempts = [
            'workspace_memberships' => [
                'id' => Uuid7::generate(),
                'tenant_id' => $this->foreignTenantId,
                'workspace_id' => $this->workspaceId,
                'user_id' => $otherUser->id,
                'role' => 'viewer',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'invitations' => [
                'id' => Uuid7::generate(),
                'tenant_id' => $this->foreignTenantId,
                'workspace_id' => $this->workspaceId,
                'email' => 'mismatch@example.test',
                'role' => 'viewer',
                'token' => Uuid7::generate(),
                'expires_at' => $now->copy()->addDay(),
                'accepted_at' => null,
                'created_by' => $this->user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'persons' => [
                'id' => Uuid7::generate(),
                'tenant_id' => $this->foreignTenantId,
                'workspace_id' => $this->workspaceId,
                'user_id' => null,
                'display_name' => 'Mismatched Person',
                'birth_date' => null,
                'managed_by_person_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'contact_points' => [
                'id' => Uuid7::generate(),
                'tenant_id' => $this->foreignTenantId,
                'workspace_id' => $this->workspaceId,
                'person_id' => $this->personId,
                'type' => 'phone',
                'value' => '0811111111',
                'is_primary' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'person_relationships' => [
                'id' => Uuid7::generate(),
                'tenant_id' => $this->foreignTenantId,
                'workspace_id' => $this->workspaceId,
                'from_person_id' => $this->personId,
                'to_person_id' => $this->otherPersonId,
                'kinship' => 'sibling',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($attempts as $table => $row) {
            TenantContext::run($this->foreignTenantId, $this->workspaceId, function () use ($table, $row) {
                $this->assertInsertRejected(
                    fn () => DB::transaction(fn () => DB::table($table)->insert($row)),
                    $table,
                    '23503',
                    'foreign_key_violation',
                );
            });
        }
    }

    /**
     * TEN-T02: without server-side context the RLS policy denies every row,
     * even for rows that exist in the database.
     */
    public function test_ten_t02_queries_without_context_return_no_rows(): void
    {
        // Sanity check: the rows exist when the correct context is set.
        TenantContext::run($this->tenantId, $this->workspaceId, function () {
            $this->assertSame(1, DB::table('persons')->where('id', $this->personId)->count());
            $this->assertSame(1, DB::table('health_profiles')->where('id', $this->healthProfileId)->count());
        });

        TenantContext::clear();

        foreach ([
            'workspaces',
            'workspace_memberships',
            'invitations',
            'persons',
            'contact_points',
            'person_relationships',
            'health_profiles',
            'care_relationships',
            'delegations',
            'access_grants',
            'consent_records',
            'audit_events',
            'security_audit_logs',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} leaked rows without RLS context");
        }
    }

    /**
     * TEN-T03: an insert carrying a tenant_id supplied by the request cannot
     * succeed. Under the caller's own context the row is rejected by RLS
     * (42501) before it can even reach the composite FK.
     */
    public function test_ten_t03_wrong_tenant_id_cannot_be_inserted(): void
    {
        TenantContext::set($this->tenantId, $this->workspaceId);

        $this->assertInsertRejected(
            fn () => DB::transaction(fn () => Person::forceCreate([
                'id' => Uuid7::generate(),
                'tenant_id' => $this->foreignTenantId,
                'workspace_id' => $this->workspaceId,
                'display_name' => 'Injected Person',
            ])),
            'persons',
            '42501',
            'row-level security policy violation',
        );

        $this->assertSame(0, DB::table('persons')->where('display_name', 'Injected Person')->count());
    }

    /**
     * TEN-T04: with the correct tenant/workspace context only that workspace's
     * rows are visible.
     */
    public function test_ten_t04_context_scopes_queries_to_one_workspace(): void
    {
        TenantContext::run($this->tenantId, $this->workspaceId, function () {
            $this->assertSame([$this->personId], DB::table('persons')->pluck('id')->all());
            $this->assertSame(1, DB::table('contact_points')->count());
            $this->assertSame(1, DB::table('workspace_memberships')->count());
            $this->assertSame(1, DB::table('person_relationships')->count());
        });

        TenantContext::run($this->tenantId, $this->otherWorkspaceId, function () {
            $this->assertSame([$this->otherPersonId], DB::table('persons')->pluck('id')->all());
            $this->assertSame(0, DB::table('contact_points')->count());
        });

        TenantContext::run($this->foreignTenantId, $this->foreignWorkspaceId, function () {
            $this->assertSame([$this->foreignPersonId], DB::table('persons')->pluck('id')->all());
        });

        // run() restored the surrounding context, so tenant A's workspace A1
        // rows are visible again.
        $this->assertSame([$this->personId], DB::table('persons')->pluck('id')->all());
    }

    /**
     * TEN-T05: RLS — not the query — is the backstop. A plain
     * `DB::table('persons')->get()` with no `where workspace_id = ...` clause
     * still returns only the current workspace's rows.
     */
    public function test_ten_t05_unfiltered_queries_are_scoped_by_rls(): void
    {
        $secondPersonId = Uuid7::generate();

        TenantContext::run($this->tenantId, $this->workspaceId, function () use ($secondPersonId) {
            DB::table('persons')->insert([
                'id' => $secondPersonId,
                'tenant_id' => $this->tenantId,
                'workspace_id' => $this->workspaceId,
                'user_id' => null,
                'display_name' => 'Person A1b',
                'birth_date' => null,
                'managed_by_person_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        TenantContext::run($this->tenantId, $this->workspaceId, function () use ($secondPersonId) {
            $rows = DB::table('persons')->get();

            $this->assertCount(2, $rows, 'RLS must hide rows from other workspaces');
            $this->assertSame(
                [$this->workspaceId],
                $rows->pluck('workspace_id')->unique()->values()->all(),
            );
            $this->assertEqualsCanonicalizing(
                [$this->personId, $secondPersonId],
                $rows->pluck('id')->all(),
            );
            $this->assertNotContains($this->otherPersonId, $rows->pluck('id')->all());
            $this->assertNotContains($this->foreignPersonId, $rows->pluck('id')->all());
        });
    }

    private function assertInsertRejected(
        callable $callback,
        string $table,
        string $expectedSqlState,
        string $expectation,
    ): void {
        try {
            $callback();
        } catch (QueryException $e) {
            $this->assertSame(
                $expectedSqlState,
                (string) $e->getCode(),
                "{$table} must reject the row with {$expectation} ({$expectedSqlState}), got: {$e->getMessage()}",
            );

            return;
        }

        $this->fail("{$table} accepted a row it must reject ({$expectation}, {$expectedSqlState})");
    }
}
