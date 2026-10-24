<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\InvitationService;
use App\Support\Database\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * TASK INV-1 - list and revoke pending invitations.
 *
 * INV-T01..INV-T08. Both routes are gated by `workspace.member.invite` (the
 * inverse of issuing an invitation), never by the weaker
 * `workspace.member.read`.
 */
class InvitationManagementTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    // INV-T01 ---------------------------------------------------------------

    public function test_inv_t01_list_returns_only_pending_unexpired_invitations_of_this_workspace(): void
    {
        [$workspace, $other, $admin] = $this->fixture();

        $this->createInvitation($workspace, $admin, 'pending-a@example.test');
        $this->createInvitation($workspace, $admin, 'pending-b@example.test', now()->addDay(), null, null, 'viewer');
        $this->createInvitation($workspace, $admin, 'expired@example.test', now()->subMinute());
        $this->createInvitation($workspace, $admin, 'accepted@example.test', now()->addDay(), now()->subHour());
        $this->createInvitation($workspace, $admin, 'revoked@example.test', now()->addDay(), null, now()->subHour());
        $this->createInvitation($other, $admin, 'other-workspace@example.test');

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $emails = collect($response->json('data'))->pluck('email')->sort()->values()->all();

        $this->assertSame(['pending-a@example.test', 'pending-b@example.test'], $emails);

        foreach ($response->json('data') as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('role', $row);
            $this->assertArrayHasKey('expires_at', $row);
            $this->assertArrayHasKey('created_at', $row);
            // A display name, never the inviter id.
            $this->assertSame('Admin Inviter', $row['invited_by']);
            $this->assertIsNotInt($row['invited_by']);
            $this->assertNotSame($admin->id, $row['invited_by']);
        }
    }

    // INV-T02 ---------------------------------------------------------------

    public function test_inv_t02_an_expired_invitation_does_not_appear(): void
    {
        [$workspace, , $admin] = $this->fixture();

        $invitation = $this->createInvitation(
            $workspace,
            $admin,
            'stale@example.test',
            now()->subSecond(),
        );

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotNull($this->freshInvitation($workspace, $invitation->id)->expires_at);
    }

    // INV-T03 ---------------------------------------------------------------

    public function test_inv_t03_revoke_returns_204_and_removes_it_from_the_list(): void
    {
        [$workspace, , $admin] = $this->fixture();

        $invitation = $this->createInvitation($workspace, $admin, 'revoke-me@example.test');

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}/invitations/{$invitation->id}")
            ->assertNoContent();

        $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotNull($this->freshInvitation($workspace, $invitation->id)->revoked_at);
    }

    // INV-T04 ---------------------------------------------------------------

    public function test_inv_t04_revoking_twice_is_still_204(): void
    {
        [$workspace, , $admin] = $this->fixture();

        $invitation = $this->createInvitation($workspace, $admin, 'double-tap@example.test');

        Sanctum::actingAs($admin);

        $path = "/api/v1/workspaces/{$workspace->id}/invitations/{$invitation->id}";

        $this->deleteJson($path)->assertNoContent();
        $first = $this->freshInvitation($workspace, $invitation->id)->revoked_at;

        $this->deleteJson($path)->assertNoContent();
        $second = $this->freshInvitation($workspace, $invitation->id)->revoked_at;

        $this->assertNotNull($first);
        // The second call is a no-op: it does not move the revocation time.
        $this->assertSame($first->toIso8601String(), $second?->toIso8601String());
    }

    // INV-T05 ---------------------------------------------------------------

    public function test_inv_t05_another_workspaces_invitation_id_is_404_not_403(): void
    {
        [$workspace, $other, $admin] = $this->fixture();

        // The actor is a member of both workspaces, so authorization succeeds
        // and only the workspace-scoped lookup can reject the id.
        $foreign = $this->createInvitation($other, $admin, 'foreign@example.test');

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}/invitations/{$foreign->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');

        $this->assertNull($this->freshInvitation($other, $foreign->id)->revoked_at);
    }

    // INV-T06 ---------------------------------------------------------------

    public function test_inv_t06_a_member_without_the_invite_permission_gets_403_on_both_routes(): void
    {
        [$workspace, , $admin, $viewer] = $this->fixture();

        $invitation = $this->createInvitation($workspace, $admin, 'viewer-cannot@example.test');

        Sanctum::actingAs($viewer);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations")
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}/invitations/{$invitation->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->assertNull($this->freshInvitation($workspace, $invitation->id)->revoked_at);
    }

    // INV-T07 ---------------------------------------------------------------

    public function test_inv_t07_each_call_writes_exactly_one_audit_row_without_the_clear_email(): void
    {
        [$workspace, , $admin] = $this->fixture();

        $email = 'audit-target@example.test';
        $invitation = $this->createInvitation($workspace, $admin, $email);

        Sanctum::actingAs($admin);

        $before = $this->auditCount($workspace);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations")->assertOk();
        $afterList = $this->auditCount($workspace);
        $this->assertSame($before + 1, $afterList, 'The list endpoint must write exactly one audit row.');

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}/invitations/{$invitation->id}")
            ->assertNoContent();
        $afterRevoke = $this->auditCount($workspace);
        $this->assertSame($afterList + 1, $afterRevoke, 'Revoke must write exactly one audit row.');

        $listRow = $this->latestAudit($workspace, 'workspace.invitation.list');
        $revokeRow = $this->latestAudit($workspace, 'workspace.invitation.revoke');

        $this->assertSame(1, $listRow->count);
        $this->assertSame(1, $revokeRow->count);

        $metadata = json_decode((string) $revokeRow->row->metadata, true);

        $this->assertIsArray($metadata);
        $this->assertArrayNotHasKey('email', $metadata);
        $this->assertSame(InvitationService::hashEmail($email), $metadata['email_hash']);

        // Nothing anywhere in the audit trail (business or security) may hold
        // the address in clear text.
        $this->assertSame(0, $this->metadataLikeCount($workspace, 'audit_events', $email));
        $this->assertSame(0, $this->metadataLikeCount($workspace, 'security_audit_logs', $email));
    }

    // INV-T08 ---------------------------------------------------------------

    public function test_inv_t08_post_invitations_still_behaves_as_before(): void
    {
        [$workspace, , $admin, $viewer] = $this->fixture();

        Sanctum::actingAs($viewer);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'still-works@example.test',
            'role' => 'caregiver',
        ])->assertStatus(403);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'still-works@example.test',
            'role' => 'caregiver',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'still-works@example.test')
            ->assertJsonPath('data.role', 'caregiver');

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        TenantContext::set($workspace->tenant_id, $workspace->id);

        try {
            $stored = DB::table('invitations')
                ->where('workspace_id', $workspace->id)
                ->where('email', 'still-works@example.test')
                ->value('token');
        } finally {
            TenantContext::clear();
        }

        $this->assertSame(hash('sha256', $token), $stored);
    }

    /**
     * @return array{0: Workspace, 1: Workspace, 2: User, 3: User}
     */
    private function fixture(): array
    {
        $tenant = $this->createTenant();
        $workspace = $this->createWorkspace($tenant, 'Invitation Management');
        $other = $this->createWorkspace($tenant, 'Other Invitation Workspace');

        $admin = User::factory()->create(['name' => 'Admin Inviter']);
        $viewer = User::factory()->create();

        $this->addMember($workspace, $admin, 'workspace_admin');
        $this->addMember($workspace, $viewer, 'viewer');
        // The actor belongs to the second workspace too, so INV-T05 exercises
        // the invitation lookup rather than workspace membership resolution.
        $this->addMember($other, $admin, 'workspace_admin');

        return [$workspace, $other, $admin, $viewer];
    }

    private function createInvitation(
        Workspace $workspace,
        User $creator,
        string $email,
        ?Carbon $expiresAt = null,
        ?Carbon $acceptedAt = null,
        ?Carbon $revokedAt = null,
        string $role = 'caregiver',
    ): Invitation {
        TenantContext::set($workspace->tenant_id, $workspace->id);

        try {
            return Invitation::query()->forceCreate([
                'tenant_id' => $workspace->tenant_id,
                'workspace_id' => $workspace->id,
                'email' => $email,
                'role' => $role,
                'token' => hash('sha256', 'token-'.$email),
                'expires_at' => $expiresAt ?? now()->addDays(InvitationService::TTL_DAYS),
                'accepted_at' => $acceptedAt,
                'revoked_at' => $revokedAt,
                'created_by' => $creator->id,
            ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function freshInvitation(Workspace $workspace, string $id): Invitation
    {
        TenantContext::set($workspace->tenant_id, $workspace->id);

        try {
            return Invitation::query()->whereKey($id)->firstOrFail();
        } finally {
            TenantContext::clear();
        }
    }

    private function auditCount(Workspace $workspace): int
    {
        TenantContext::set($workspace->tenant_id, $workspace->id);

        try {
            return DB::table('audit_events')->count();
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * @return object{count: int, row: object}
     */
    private function latestAudit(Workspace $workspace, string $action): object
    {
        TenantContext::set($workspace->tenant_id, $workspace->id);

        try {
            return (object) [
                'count' => DB::table('audit_events')->where('action', $action)->count(),
                'row' => DB::table('audit_events')->where('action', $action)->latest('occurred_at')->firstOrFail(),
            ];
        } finally {
            TenantContext::clear();
        }
    }

    private function metadataLikeCount(Workspace $workspace, string $table, string $needle): int
    {
        TenantContext::set($workspace->tenant_id, $workspace->id);

        try {
            return DB::table($table)
                ->whereRaw('metadata::text like ?', ["%{$needle}%"])
                ->count();
        } finally {
            TenantContext::clear();
        }
    }
}
