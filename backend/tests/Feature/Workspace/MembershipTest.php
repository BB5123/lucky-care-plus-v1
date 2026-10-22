<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * MEM-T01 — `workspace.member.read` follows Matrix A of the frozen baseline:
 * Owner, Guardian and Workspace Admin may read the member list; Viewer,
 * Caregiver and Platform roles may not (D = deny by default). A non-member is
 * indistinguishable from a missing workspace (404) so existence never leaks.
 */
class MembershipTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_mem_t01_workspace_admin_can_read_members(): void
    {
        $tenant = $this->createTenant();

        $admin = User::factory()->create(['name' => 'Admin User']);
        $viewer = User::factory()->create(['name' => 'Viewer User']);

        $workspace = $this->createWorkspace($tenant, 'Members Workspace');
        $this->addMember($workspace, $admin, 'workspace_admin');
        $this->addMember($workspace, $viewer, 'viewer');

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Admin User', 'role' => 'workspace_admin', 'status' => 'active'])
            ->assertJsonFragment(['name' => 'Viewer User', 'role' => 'viewer', 'status' => 'active'])
            ->assertJsonFragment(['id' => $viewer->id, 'user_id' => $viewer->id]);
    }

    public function test_mem_t01_viewer_and_caregiver_are_denied_the_member_list(): void
    {
        $tenant = $this->createTenant();

        $workspace = $this->createWorkspace($tenant, 'Denied Workspace');

        $viewer = User::factory()->create();
        $caregiver = User::factory()->create();
        $this->addMember($workspace, $viewer, 'viewer');
        $this->addMember($workspace, $caregiver, 'caregiver');

        foreach ([$viewer, $caregiver] as $actor) {
            Sanctum::actingAs($actor);

            $this->getJson("/api/v1/workspaces/{$workspace->id}/members")
                ->assertStatus(403)
                ->assertJsonPath('code', 'forbidden');
        }
    }

    public function test_mem_t02_non_member_gets_404(): void
    {
        $tenant = $this->createTenant();

        $workspace = $this->createWorkspace($tenant, 'Private Workspace');
        $this->addMember($workspace, User::factory()->create(), 'workspace_admin');

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/workspaces/{$workspace->id}/members")
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    public function test_me_lists_the_users_workspace_memberships(): void
    {
        $tenant = $this->createTenant();
        $member = User::factory()->create();
        $workspace = $this->createWorkspace($tenant, 'Me Workspace');
        $this->addMember($workspace, $member, 'caregiver');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.workspaces.0.workspace_id', $workspace->id)
            ->assertJsonPath('data.workspaces.0.id', $workspace->id)
            ->assertJsonPath('data.workspaces.0.name', 'Me Workspace')
            ->assertJsonPath('data.workspaces.0.role', 'caregiver');
    }
}
