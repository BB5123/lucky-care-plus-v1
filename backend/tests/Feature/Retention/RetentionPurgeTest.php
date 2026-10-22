<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Retention\Exceptions\RetentionOverrideException;
use App\Modules\Retention\Models\DataHold;
use App\Modules\Retention\Services\LegalHold;
use App\Modules\Retention\Services\PurgeService;
use App\Modules\Retention\Services\RetentionPolicy;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-T01, RET-T02, RET-T03, RET-T05, RET-T06, RET-T07 — purge safety.
 */
class RetentionPurgeTest extends TestCase
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

        $this->tenant = $this->createTenant('retention-purge-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Retention Purge Workspace');

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

    public function test_ret_t01_rows_past_the_effective_period_are_purged(): void
    {
        config(['retention.dry_run' => false]);

        $expired = $this->measurement(now()->subMonths(61));
        $current = $this->measurement(now()->subMonths(59));

        $report = app(PurgeService::class)->purgeBatch('health_measurements');

        $this->assertSame('health_measurements', $report->type);
        $this->assertFalse($report->dryRun);
        $this->assertSame(1, $report->purged);

        // Health data is soft-deleted, not physically removed (DM-R01).
        $this->assertSoftDeleted('health_measurements', ['id' => $expired->id]);
        $this->assertNotSoftDeleted('health_measurements', ['id' => $current->id]);
    }

    public function test_ret_t02_a_row_covered_by_an_active_legal_hold_is_not_purged(): void
    {
        config(['retention.dry_run' => false]);

        $measurement = $this->measurement(now()->subMonths(61));

        $hold = DataHold::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profileId,
            'reason' => 'litigation-hold',
            'created_by' => $this->user->id,
            'until' => now()->addYear(),
        ]);

        $report = app(PurgeService::class)->purgeBatch('health_measurements');

        $this->assertSame(0, $report->purged);
        $this->assertSame(1, $report->held);
        $this->assertNotSoftDeleted('health_measurements', ['id' => $measurement->id]);

        // An expired hold no longer blocks the purge.
        $hold->forceFill(['until' => now()->subDay()])->save();

        $report = app(PurgeService::class)->purgeBatch('health_measurements');

        $this->assertSame(1, $report->purged);
        $this->assertSoftDeleted('health_measurements', ['id' => $measurement->id]);
    }

    public function test_ret_t02b_a_workspace_wide_hold_blocks_every_profile(): void
    {
        config(['retention.dry_run' => false]);

        $measurement = $this->measurement(now()->subMonths(61));

        $hold = DataHold::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => null,
            'reason' => 'workspace-wide-hold',
            'created_by' => $this->user->id,
            'until' => null,
        ]);

        $this->assertTrue(app(LegalHold::class)->isHeld($this->workspace->id));

        $report = app(PurgeService::class)->purgeBatch('health_measurements');

        $this->assertSame(0, $report->purged);
        $this->assertSame(1, $report->held);
        $this->assertNotSoftDeleted('health_measurements', ['id' => $measurement->id]);

        $this->assertTrue(app(LegalHold::class)->release($hold->id, $this->user->id));
        $this->assertFalse(app(LegalHold::class)->isHeld($this->workspace->id));
    }

    public function test_ret_t03_tenant_override_within_bounds_is_honoured_and_out_of_bounds_is_rejected(): void
    {
        config(['retention.dry_run' => false]);

        $measurement = $this->measurement(now()->subMonths(70));

        $this->tenant->forceFill([
            'retention_override' => ['health_measurements' => 120],
        ])->save();

        $policy = app(RetentionPolicy::class);

        $this->assertSame(120, $policy->monthsFor('health_measurements', $this->tenant->fresh()));

        $report = app(PurgeService::class)->purgeBatch('health_measurements');

        $this->assertSame(0, $report->purged);
        $this->assertNotSoftDeleted('health_measurements', ['id' => $measurement->id]);

        // DM-R06: below the 24-month minimum must be rejected (422 at the API).
        $this->tenant->forceFill([
            'retention_override' => ['health_measurements' => 12],
        ])->save();

        try {
            $policy->monthsFor('health_measurements', $this->tenant->fresh());
            $this->fail('A 12-month health data override must be rejected.');
        } catch (RetentionOverrideException $e) {
            $this->assertStringContainsString('24 and 120', $e->getMessage());
        }

        try {
            app(PurgeService::class)->purgeBatch('health_measurements');
            $this->fail('The purge must refuse to run with an invalid override.');
        } catch (RetentionOverrideException $e) {
            $this->assertStringContainsString('24 and 120', $e->getMessage());
        }

        $this->assertNotSoftDeleted('health_measurements', ['id' => $measurement->id]);
    }

    public function test_ret_t05_dry_run_reports_the_batch_and_deletes_nothing(): void
    {
        config(['retention.dry_run' => true]);

        $measurement = $this->measurement(now()->subMonths(61));

        $this->artisan('retention:purge', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNotSoftDeleted('health_measurements', ['id' => $measurement->id]);

        $report = app(PurgeService::class)->purgeBatch('health_measurements');

        $this->assertTrue($report->dryRun);
        $this->assertSame(1, $report->evaluated);
        $this->assertSame(0, $report->purged);
    }

    public function test_ret_t06_audit_tables_are_never_purged_and_one_audit_row_is_written_per_batch(): void
    {
        config(['retention.dry_run' => false]);

        $now = now();

        DB::table('audit_events')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'retention.fixture',
            'target_type' => null,
            'target_id' => null,
            'result' => 'allow',
            'reason_code' => null,
            'metadata' => json_encode([]),
            'occurred_at' => $now->copy()->subMonths(61),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('security_audit_logs')->insert([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'occurred_at' => $now->copy()->subMonths(61),
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'retention.fixture',
            'target_type' => null,
            'target_id' => null,
            'result' => 'allow',
            'reason_code' => null,
            'workspace_id' => $this->workspace->id,
            'metadata' => json_encode([]),
            'prev_hash' => null,
            'hash' => DB::raw("decode('00', 'hex')"),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $auditBefore = DB::table('audit_events')->count();
        $securityBefore = DB::table('security_audit_logs')->count();

        $this->measurement(now()->subMonths(61));

        app(PurgeService::class)->purgeBatch('health_measurements');

        $this->assertSame($auditBefore, DB::table('audit_events')->count());
        $this->assertSame(
            $securityBefore + 1,
            DB::table('security_audit_logs')->count(),
            'One batch must write exactly one security audit row.',
        );

        foreach (['audit_events', 'security_audit_logs', 'audit_months'] as $forbidden) {
            try {
                app(PurgeService::class)->purgeBatch($forbidden);
                $this->fail("Purge must reject [{$forbidden}] (DM-R05).");
            } catch (\InvalidArgumentException) {
                // Expected: purge never targets audit data.
            }
        }

        $this->assertSame($auditBefore, DB::table('audit_events')->count());
        $this->assertSame($securityBefore + 1, DB::table('security_audit_logs')->count());
    }

    public function test_ret_t07_running_the_same_batch_twice_is_safe(): void
    {
        config(['retention.dry_run' => false]);

        $measurement = $this->measurement(now()->subMonths(61));

        $service = app(PurgeService::class);

        $first = $service->purgeBatch('health_measurements');
        $second = $service->purgeBatch('health_measurements');

        $this->assertSame(1, $first->purged);
        $this->assertSame(0, $second->purged);
        $this->assertSame(0, $second->evaluated);
        $this->assertSoftDeleted('health_measurements', ['id' => $measurement->id]);
    }

    private function measurement(CarbonInterface $recordedAt): HealthMeasurement
    {
        return HealthMeasurement::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profileId,
            'type' => 'weight',
            'value' => 70.5,
            'unit' => 'kg',
            'recorded_at' => $recordedAt,
            'recorded_by' => $this->user->id,
            'source' => 'manual',
            'version' => 1,
        ]);
    }
}
