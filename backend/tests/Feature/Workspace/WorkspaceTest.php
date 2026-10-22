<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * WS-T01, WS-T02 — creator becomes workspace_admin; non-members see 404.
 */
class WorkspaceTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_ws_t01_create_workspace_makes_the_creator_workspace_admin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/workspaces', ['name' => 'My Family']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'My Family')
            ->assertJsonPath('data.role', 'workspace_admin')
            ->assertJsonPath('data.status', 'active');

        $workspaceId = $response->json('data.id');
        $tenantId = Tenant::query()->where('slug', 'default')->value('id');

        TenantContext::set($tenantId, $workspaceId);

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->first();

        TenantContext::clear();

        $this->assertNotNull($membership);
        $this->assertSame('workspace_admin', $membership->role);
        $this->assertSame('active', $membership->status);
    }

    public function test_ws_t02_non_member_gets_404_not_403(): void
    {
        $tenant = $this->createTenant();

        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $workspace = $this->createWorkspace($tenant, 'Private Family');
        $this->addMember($workspace, $owner, 'workspace_admin');

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'out_of_scope');
    }
}
