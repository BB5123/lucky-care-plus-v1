<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-G01..G07 - the account state is enforced on the HTTP surface (P2).
 *
 * Every workspace route goes through `ResolveWorkspaceContext`, so the gate is
 * tested there: writes in read_only, reads in archived, the export exception in
 * both, 410 for deleted, and the audit row a block leaves behind.
 */
class WorkspaceStateGateTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private User $user;

    private string $profileId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('retention-gate-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Retention Gate Workspace');

        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'workspace_admin');

        $this->context();

        $person = Person::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Gate Person',
        ]);

        $profile = HealthProfile::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $person->id,
            'version' => 1,
        ]);

        $this->profileId = $profile->id;

        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_ret_g01_read_only_refuses_writes_and_allows_reads(): void
    {
        $this->setState(RetentionState::ReadOnly);

        $this->postJson($this->invitationsPath(), $this->invitationBody())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'workspace_read_only')
            ->assertJsonPath('retention_state', 'read_only');

        $this->context();

        $this->getJson("/api/v1/workspaces/{$this->workspace->id}")
            ->assertOk();
    }

    public function test_ret_g02_read_only_still_allows_export(): void
    {
        $this->setState(RetentionState::ReadOnly);
        Storage::fake('local');

        $response = $this->postJson($this->exportsPath(), ['format' => 'json']);

        // The gate must not classify export as a write; whatever the export
        // service answers (202, or a consent denial), it is not the state gate.
        $this->assertNotSame(409, $response->status());
        $this->assertNotContains(
            $response->json('code'),
            ['workspace_read_only', 'workspace_archived', 'workspace_scheduled_deletion'],
        );
    }

    public function test_ret_g03_archived_refuses_reads_but_allows_the_metadata_read(): void
    {
        $this->setState(RetentionState::Archived);

        $this->getJson("/api/v1/workspaces/{$this->workspace->id}/dashboard")
            ->assertStatus(409)
            ->assertJsonPath('code', 'workspace_archived');

        $this->context();

        // The profile picker is not metadata: 409 before authorization.
        $this->getJson("/api/v1/workspaces/{$this->workspace->id}/profiles")
            ->assertStatus(409)
            ->assertJsonPath('code', 'workspace_archived');

        $this->context();

        $this->getJson("/api/v1/workspaces/{$this->workspace->id}")
            ->assertOk();
    }

    public function test_ret_g04_scheduled_deletion_has_its_own_code(): void
    {
        $this->setState(RetentionState::ScheduledDeletion);

        $this->postJson($this->invitationsPath(), $this->invitationBody())
            ->assertStatus(409)
            ->assertJsonPath('code', 'workspace_scheduled_deletion');

        $this->context();

        $this->getJson("/api/v1/workspaces/{$this->workspace->id}")
            ->assertOk();
    }

    public function test_ret_g05_deleted_answers_410(): void
    {
        $this->setState(RetentionState::Deleted);

        $this->getJson("/api/v1/workspaces/{$this->workspace->id}")
            ->assertStatus(410)
            ->assertJsonPath('code', 'workspace_deleted');
    }

    public function test_ret_g06_an_active_workspace_is_untouched_and_a_block_is_audited(): void
    {
        $this->postJson($this->invitationsPath(), $this->invitationBody())
            ->assertCreated();

        $this->context();

        $this->setState(RetentionState::Archived);

        $this->postJson($this->invitationsPath(), $this->invitationBody())
            ->assertStatus(409);

        $this->context();

        $blocked = DB::table('security_audit_logs')
            ->where('action', 'workspace.retention_block')
            ->where('result', 'deny')
            ->count();

        $this->assertSame(1, $blocked, 'A blocked request must leave exactly one deny row.');
    }

    public function test_ret_g07_a_block_in_one_workspace_does_not_affect_another(): void
    {
        $other = $this->createWorkspace($this->tenant, 'Still Active Workspace');
        $this->addMember($other, $this->user, 'workspace_admin');

        $this->context();
        $this->setState(RetentionState::Archived);

        $this->postJson("/api/v1/workspaces/{$other->id}/invitations", $this->invitationBody())
            ->assertCreated();

        $this->context();

        $this->assertSame(RetentionState::Archived, $this->workspace->fresh()->retention_state);
        $this->assertSame(RetentionState::Active, $other->fresh()->retention_state);
    }

    private function setState(RetentionState $state): void
    {
        $this->context();

        $this->workspace->forceFill([
            'retention_state' => $state,
            'retention_state_changed_at' => now(),
            'retention_expires_at' => $state === RetentionState::Active ? null : now()->addDays(30),
        ])->save();

        $this->workspace->refresh();
    }

    private function context(): void
    {
        TenantContext::set($this->tenant->id, $this->workspace->id);
    }

    private function invitationsPath(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/invitations";
    }

    /**
     * @return array<string, string>
     */
    private function invitationBody(): array
    {
        return ['email' => 'caregiver@example.com', 'role' => 'caregiver'];
    }

    private function exportsPath(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/profiles/{$this->profileId}/exports";
    }
}
