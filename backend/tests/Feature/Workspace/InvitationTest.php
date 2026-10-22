<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * INV-T01, INV-T02 — invite permission, hashed single-use tokens, 409/404.
 */
class InvitationTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_inv_t01_invite_requires_the_invite_permission(): void
    {
        [$workspace, $admin, $viewer] = $this->fixture();

        Sanctum::actingAs($viewer);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'guest@example.test',
            'role' => 'caregiver',
        ])
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'guest@example.test',
            'role' => 'caregiver',
        ])->assertCreated();

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        TenantContext::set($workspace->tenant_id, $workspace->id);
        $stored = DB::table('invitations')->where('email', 'guest@example.test')->value('token');
        TenantContext::clear();

        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertNotSame($token, $stored);
    }

    public function test_inv_t02_accept_creates_membership_then_409_and_expired_404(): void
    {
        [$workspace, $admin] = $this->fixture();
        $invitee = User::factory()->create(['email' => 'invitee@example.test']);

        Sanctum::actingAs($admin);

        $token = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'invitee@example.test',
            'role' => 'caregiver',
        ])->assertCreated()->json('data.token');

        Sanctum::actingAs($invitee);

        $this->postJson("/api/v1/invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.role', 'caregiver')
            ->assertJsonPath('data.workspace.id', $workspace->id);

        TenantContext::set($workspace->tenant_id, $workspace->id);
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $invitee->id)
            ->first();
        TenantContext::clear();

        $this->assertNotNull($membership);
        $this->assertSame('caregiver', $membership->role);

        // A second accept finds the membership and answers 409.
        $this->postJson("/api/v1/invitations/{$token}/accept")
            ->assertStatus(409)
            ->assertJsonPath('code', 'domain_rule');

        // An expired token is indistinguishable from an unknown one (404).
        $expiredToken = 'expired-token-'.Uuid7::generate();
        $this->createExpiredInvitation($workspace, $admin, $expiredToken);

        $this->postJson("/api/v1/invitations/{$expiredToken}/accept")
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    /**
     * @return array{0: Workspace, 1: User, 2: User}
     */
    private function fixture(): array
    {
        $tenant = $this->createTenant();
        $workspace = $this->createWorkspace($tenant, 'Invitation Workspace');

        $admin = User::factory()->create();
        $viewer = User::factory()->create();

        $this->addMember($workspace, $admin, 'workspace_admin');
        $this->addMember($workspace, $viewer, 'viewer');

        return [$workspace, $admin, $viewer];
    }

    private function createExpiredInvitation(Workspace $workspace, User $admin, string $plainToken): void
    {
        TenantContext::set($workspace->tenant_id, $workspace->id);

        Invitation::query()->forceCreate([
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'email' => 'expired@example.test',
            'role' => 'viewer',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->subMinute(),
            'accepted_at' => null,
            'created_by' => $admin->id,
        ]);

        TenantContext::clear();
    }
}
