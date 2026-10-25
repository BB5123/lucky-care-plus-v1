<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Retention\Models\DataHold;
use App\Modules\Retention\Services\LegalHold;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-S01..S06 - the five account states and the arrows between them (P2).
 *
 * Windows come from `retention.state_windows_days`; until billing assigns a
 * plan, the Free-with-health-data floor applies (30 days read-only + 11 months
 * archived), and the shorter 60-day row when no health data exists.
 */
class RetentionStateTest extends TestCase
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

        $this->tenant = $this->createTenant('retention-state-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Retention State Workspace');

        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'profile_owner');

        TenantContext::set($this->tenant->id, $this->workspace->id);

        $person = Person::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Retention Person',
        ]);

        $profile = HealthProfile::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $person->id,
            'version' => 1,
        ]);

        $this->profileId = $profile->id;
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_ret_s01_a_lapse_enters_read_only_and_is_audited(): void
    {
        $this->freezeTime();
        $this->measurement();

        $lifecycle = app(WorkspaceLifecycle::class);
        $workspace = $lifecycle->lapse($this->workspace, 'payment_failed');

        $this->assertSame(RetentionState::ReadOnly, $lifecycle->state($workspace));
        $this->assertNotNull($workspace->lapsed_at);
        $this->assertNotNull($workspace->retention_expires_at);
        $this->assertSame(
            30,
            (int) round($workspace->lapsed_at->diffInDays($workspace->retention_expires_at)),
        );

        $audit = DB::table('security_audit_logs')
            ->where('action', 'retention.state')
            ->orderByDesc('seq')
            ->first();

        $this->assertNotNull($audit, 'A state transition must write one audit row.');
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame('active', $metadata['from']);
        $this->assertSame('read_only', $metadata['to']);
        $this->assertSame('payment_failed', $metadata['reason']);
    }

    public function test_ret_s02_the_advance_walks_read_only_archived_and_scheduled_deletion(): void
    {
        $this->freezeTime();
        $this->measurement();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);

        // Nothing is due before the window ends.
        $this->travel(29)->days();
        $this->assertNull($lifecycle->advance($this->workspace->fresh()));

        $this->travel(2)->days();
        $advanced = $lifecycle->advance($this->workspace->fresh());
        $this->assertNotNull($advanced);
        $this->assertSame(RetentionState::Archived, $lifecycle->state($advanced));
        $this->assertSame(335, (int) round(now()->diffInDays($advanced->retention_expires_at)));

        $this->travel(336)->days();
        $advanced = $lifecycle->advance($this->workspace->fresh());
        $this->assertNotNull($advanced);
        $this->assertSame(RetentionState::ScheduledDeletion, $lifecycle->state($advanced));
        $this->assertSame(30, (int) round(now()->diffInDays($advanced->retention_expires_at)));

        // The final arrow is P3/P4: the command must not pretend the data is gone.
        $this->travel(31)->days();
        $this->assertNull($lifecycle->advance($this->workspace->fresh()));
        $this->assertSame(
            RetentionState::ScheduledDeletion,
            $lifecycle->state($this->workspace->fresh()),
        );
    }

    public function test_ret_s03_a_workspace_without_health_data_gets_the_short_archive_window(): void
    {
        $this->freezeTime();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);

        $this->travel(31)->days();
        $advanced = $lifecycle->advance($this->workspace->fresh());

        $this->assertNotNull($advanced);
        $this->assertSame(RetentionState::Archived, $lifecycle->state($advanced));
        $this->assertSame(30, (int) round(now()->diffInDays($advanced->retention_expires_at)));
    }

    public function test_ret_s04_an_active_legal_hold_pins_the_workspace_in_read_only(): void
    {
        $this->freezeTime();
        $this->measurement();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);

        $hold = DataHold::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => null,
            'reason' => 'litigation-hold',
            'created_by' => $this->user->id,
            'until' => null,
        ]);

        $this->travel(31)->days();

        $this->assertNull($lifecycle->advance($this->workspace->fresh()));
        $this->assertSame(RetentionState::ReadOnly, $lifecycle->state($this->workspace->fresh()));
        $this->assertTrue($lifecycle->held($this->workspace->fresh()));

        // Releasing the hold lets the timer continue.
        $this->assertTrue(app(LegalHold::class)->release($hold->id, $this->user->id));

        $advanced = $lifecycle->advance($this->workspace->fresh());
        $this->assertNotNull($advanced);
        $this->assertSame(RetentionState::Archived, $lifecycle->state($advanced));
    }

    public function test_ret_s05_restore_returns_to_active_and_deleted_is_terminal(): void
    {
        $this->freezeTime();
        $this->measurement();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);

        $restored = $lifecycle->restore($this->workspace, 'payment');

        $this->assertSame(RetentionState::Active, $lifecycle->state($restored));
        $this->assertNull($restored->retention_expires_at);
        $this->assertNull($restored->lapsed_at);

        // Only a scheduled_deletion workspace can be marked deleted in P2.
        $this->expectException(LogicException::class);
        $lifecycle->markDeleted($this->workspace);
    }

    public function test_ret_s06_a_deleted_workspace_cannot_lapse_or_be_restored(): void
    {
        $this->freezeTime();

        $workspace = $this->workspace;
        $workspace->forceFill([
            'retention_state' => RetentionState::Deleted,
            'retention_state_changed_at' => now(),
        ])->save();

        $lifecycle = app(WorkspaceLifecycle::class);

        try {
            $lifecycle->restore($workspace);
            $this->fail('A deleted workspace must not be restorable.');
        } catch (LogicException) {
            // Expected: the state is terminal.
        }

        try {
            $lifecycle->lapse($workspace);
            $this->fail('A deleted workspace must not lapse.');
        } catch (LogicException) {
            // Expected.
        }

        // Both calls were refused and the terminal state is unchanged.
        $this->assertSame(RetentionState::Deleted, $lifecycle->state($workspace->fresh()));
    }

    public function test_ret_s07_mark_deleted_requires_the_scheduled_state(): void
    {
        $this->freezeTime();
        $this->measurement();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);
        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());
        $this->travel(336)->days();
        $lifecycle->advance($this->workspace->fresh());

        $deleted = $lifecycle->markDeleted($this->workspace->fresh());

        $this->assertSame(RetentionState::Deleted, $lifecycle->state($deleted));
        $this->assertNull($deleted->retention_expires_at);
    }

    public function test_ret_s08_the_commands_drive_the_transitions(): void
    {
        $this->freezeTime();
        $this->measurement();

        $this->artisan('retention:lapse', [
            'workspace' => $this->workspace->id,
            '--reason' => 'payment_failed',
        ])->assertSuccessful();

        $this->assertSame(
            RetentionState::ReadOnly,
            $this->workspace->fresh()->retention_state,
        );

        // The scheduled command is a dry run here: nothing may change.
        $this->artisan('retention:advance', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(RetentionState::ReadOnly, $this->workspace->fresh()->retention_state);

        $this->artisan('retention:restore', ['workspace' => $this->workspace->id])
            ->assertSuccessful();

        $this->assertSame(RetentionState::Active, $this->workspace->fresh()->retention_state);
    }

    private function measurement(): HealthMeasurement
    {
        return HealthMeasurement::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profileId,
            'type' => 'weight',
            'value' => 70.5,
            'unit' => 'kg',
            'recorded_at' => now()->subDay(),
            'recorded_by' => $this->user->id,
            'source' => 'manual',
            'version' => 1,
        ]);
    }
}
