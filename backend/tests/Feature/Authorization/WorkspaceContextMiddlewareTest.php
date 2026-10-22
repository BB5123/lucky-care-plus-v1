<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ISO-T01..T04 — the workspace-context middleware is the only place that
 * turns a route UUID into a tenant/workspace RLS context.
 */
class WorkspaceContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $workspaceAId;

    private string $workspaceBId;

    private User $memberA;

    private User $memberB;

    private User $nonMember;

    private Person $personA;

    private Person $personB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Uuid7::generate();
        $this->workspaceAId = Uuid7::generate();
        $this->workspaceBId = Uuid7::generate();

        TenantContext::set($this->tenantId, '');

        Tenant::forceCreate([
            'id' => $this->tenantId,
            'name' => 'Middleware Tenant',
            'slug' => 'middleware-tenant',
        ]);

        Workspace::forceCreate([
            'id' => $this->workspaceAId,
            'tenant_id' => $this->tenantId,
            'name' => 'Workspace A',
            'slug' => 'workspace-a',
        ]);

        Workspace::forceCreate([
            'id' => $this->workspaceBId,
            'tenant_id' => $this->tenantId,
            'name' => 'Workspace B',
            'slug' => 'workspace-b',
        ]);

        $this->memberA = User::factory()->create();
        $this->memberB = User::factory()->create();
        $this->nonMember = User::factory()->create();

        TenantContext::set($this->tenantId, $this->workspaceAId);

        WorkspaceMembership::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceAId,
            'user_id' => $this->memberA->id,
            'role' => 'caregiver',
            'status' => 'active',
        ]);

        $this->personA = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceAId,
            'user_id' => null,
            'display_name' => 'Person A',
        ]);

        TenantContext::run($this->tenantId, $this->workspaceBId, function (): void {
            WorkspaceMembership::forceCreate([
                'id' => Uuid7::generate(),
                'tenant_id' => $this->tenantId,
                'workspace_id' => $this->workspaceBId,
                'user_id' => $this->memberB->id,
                'role' => 'caregiver',
                'status' => 'active',
            ]);

            $this->personB = Person::forceCreate([
                'id' => Uuid7::generate(),
                'tenant_id' => $this->tenantId,
                'workspace_id' => $this->workspaceBId,
                'user_id' => null,
                'display_name' => 'Person B',
            ]);
        });
    }

    public function test_iso_t01_valid_member_gets_the_real_tenant_and_workspace(): void
    {
        $this->actingAs($this->memberA);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspaceAId}/ping");

        $response->assertOk()->assertJson([
            'data' => [
                'ok' => true,
                'workspace_id' => $this->workspaceAId,
                'tenant_id' => $this->tenantId,
            ],
        ]);
    }

    public function test_iso_t02_non_member_gets_404_problem_json(): void
    {
        $this->actingAs($this->nonMember);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspaceAId}/ping");

        $response
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson([
                'status' => 404,
                'code' => 'out_of_scope',
            ]);
    }

    public function test_iso_t03_unfiltered_queries_inside_the_request_are_scoped_by_rls(): void
    {
        $this->registerPersonCountRoute();
        $this->actingAs($this->memberA);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspaceAId}/test-persons-count");

        $response->assertOk()->assertJson([
            'data' => [
                'workspace_id' => $this->workspaceAId,
                'count' => 1,
            ],
        ]);

        // Person B exists in the same tenant but another workspace; RLS inside
        // the request must hide it even though the query has no WHERE clause.
        $this->assertNotSame($this->personA->id, $this->personB->id);
    }

    public function test_iso_t04_context_is_cleared_after_the_request_and_does_not_leak(): void
    {
        $this->registerPersonCountRoute();
        $this->actingAs($this->memberA);

        $this->getJson("/api/v1/workspaces/{$this->workspaceAId}/test-persons-count")
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $tenantSetting = DB::selectOne("select current_setting('app.tenant_id', true) as value")->value;
        $workspaceSetting = DB::selectOne("select current_setting('app.workspace_id', true) as value")->value;

        $this->assertTrue($tenantSetting === null || $tenantSetting === '', 'app.tenant_id leaked after the request');
        $this->assertTrue($workspaceSetting === null || $workspaceSetting === '', 'app.workspace_id leaked after the request');
        $this->assertSame(0, DB::table('persons')->count(), 'tenant data leaked without an active context');

        // The next request resolves its own workspace and sees only its rows.
        $this->actingAs($this->memberB);

        $this->getJson("/api/v1/workspaces/{$this->workspaceBId}/test-persons-count")
            ->assertOk()
            ->assertJson([
                'data' => [
                    'workspace_id' => $this->workspaceBId,
                    'count' => 1,
                ],
            ]);
    }

    /**
     * Test-only route proving that an unfiltered query inside the request sees
     * only the workspace the middleware resolved. Production routes stay in
     * routes/api.php (the task requires exactly one new endpoint there).
     */
    private function registerPersonCountRoute(): void
    {
        Route::middleware(['auth:sanctum', 'workspace.context'])
            ->get('/api/v1/workspaces/{workspace}/test-persons-count', function (Request $request) {
                return response()->json([
                    'data' => [
                        'workspace_id' => $request->attributes->get('workspace')->id,
                        'count' => DB::table('persons')->count(),
                    ],
                ]);
            });
    }
}
