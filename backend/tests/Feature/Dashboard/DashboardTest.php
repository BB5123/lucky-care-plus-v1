<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * P1.5 acceptance suite — dashboard aggregate (ADR-010).
 *
 * Every test goes through the HTTP surface so the middleware stack
 * (`workspace.context`), the per-card authorization, and the 30-second cache
 * are all exercised as they will be in production.
 */
class DashboardTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    private User $owner;

    private User $caregiver;

    private User $limitedMember;

    private User $outsider;

    private Person $ownerPerson;

    private Person $dependentPerson;

    private HealthProfile $ownerProfile;

    private HealthProfile $dependentProfile;

    private HealthProfile $otherWorkspaceProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('dash-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Dash Workspace');
        $this->otherWorkspace = $this->createWorkspace($this->tenant, 'Dash Other Workspace');

        $this->owner = User::factory()->create();
        $this->caregiver = User::factory()->create();
        $this->limitedMember = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->addMember($this->workspace, $this->owner, 'profile_owner');
        $this->addMember($this->workspace, $this->caregiver, 'caregiver');
        $this->addMember($this->workspace, $this->limitedMember, 'caregiver');
        $this->addMember($this->otherWorkspace, $this->owner, 'profile_owner');

        $this->useWorkspace($this->workspace);

        $this->ownerPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'display_name' => 'คุณพ่อ',
        ]);

        $this->ownerProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $this->ownerPerson->id,
            'version' => 1,
        ]);

        $this->dependentPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => null,
            'display_name' => 'คุณแม่',
            'managed_by_person_id' => $this->ownerPerson->id,
        ]);

        $this->dependentProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $this->dependentPerson->id,
            'managed_by_person_id' => $this->ownerPerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->otherWorkspace);

        $otherPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'user_id' => null,
            'display_name' => 'Other Workspace Person',
        ]);

        $this->otherWorkspaceProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'person_id' => $otherPerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->workspace);
    }

    // -- AP-T05 -------------------------------------------------------------

    public function test_ap_t05_vitals_scope_never_returns_lab_fields(): void
    {
        $this->grant($this->caregiver, ['profile.basic', 'vitals'], ['read'], $this->dependentProfile);
        $this->measurement($this->dependentProfile, 'blood_pressure', 150, 95);

        Sanctum::actingAs($this->caregiver);

        $response = $this->getJson($this->dashboardPath().'?profile_id='.$this->dependentProfile->id.'&bypass_cache=1')
            ->assertOk()
            ->assertJsonPath('data.vitals.visible', true);

        $data = $response->json('data');

        $this->assertNotEmpty($data['vitals']['cards']);
        $this->assertNoLabFields($data);
    }

    // -- AP-T06 -------------------------------------------------------------

    public function test_ap_t06_revocation_is_immediate_with_bypass_and_lands_within_one_ttl_with_cache(): void
    {
        // Keep profile.basic separate so revoking only the vitals grant leaves
        // the profile itself visible; the vitals card is what must disappear.
        $this->grant($this->caregiver, ['profile.basic'], ['read'], $this->dependentProfile);
        $grant = $this->grant($this->caregiver, ['vitals'], ['read'], $this->dependentProfile);
        $measurement = $this->measurement($this->dependentProfile, 'blood_pressure', 150, 95);

        Sanctum::actingAs($this->caregiver);

        $path = $this->dashboardPath().'?profile_id='.$this->dependentProfile->id;

        // Warm the 30-second cache.
        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.vitals.visible', true)
            ->assertJsonPath('data.vitals.cards.0.latest.value', 150);

        // Out-of-band write (no model event) must stay invisible until the TTL
        // expires — this proves the request really is served from the cache.
        $this->useWorkspace();
        DB::table('health_measurements')->where('id', $measurement->id)->update(['value' => 190]);

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.vitals.cards.0.latest.value', 150);

        $this->getJson($path.'&bypass_cache=1')
            ->assertOk()
            ->assertJsonPath('data.vitals.cards.0.latest.value', 190);

        $this->travel(31)->seconds();

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.vitals.cards.0.latest.value', 190);

        // Revoking a grant bumps authz_version, so even the cached path sees
        // the change immediately (the TTL is the outer bound, not the only
        // invalidation mechanism).
        $this->useWorkspace();
        $grant->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        $this->getJson($path.'&bypass_cache=1')
            ->assertOk()
            ->assertJsonPath('data.vitals.visible', false);

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.vitals.visible', false);
    }

    // -- DASH-T01 -----------------------------------------------------------

    public function test_dash_t01_non_member_gets_404_out_of_scope(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson($this->dashboardPath())
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    // -- DASH-T02 -----------------------------------------------------------

    public function test_dash_t02_tasks_today_counts_and_safe_cards_only(): void
    {
        // Freeze mid-morning so "today", "overdue", and "tomorrow" are stable
        // regardless of the wall clock the suite runs at.
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));

        $this->task($this->ownerProfile, [
            'title' => 'Morning meds',
            'description' => 'Secret description that must never leave the server',
            'due_at' => now()->startOfDay()->addHours(11),
        ]);
        $this->task($this->ownerProfile, [
            'title' => 'Afternoon walk',
            'due_at' => now()->startOfDay()->addHours(15),
        ]);
        $this->task($this->ownerProfile, [
            'title' => 'Breakfast',
            'status' => CareTask::STATUS_COMPLETED,
            'outcome' => CareTask::OUTCOME_DONE,
            'completed_at' => now(),
            'due_at' => now()->startOfDay()->addHours(8),
        ]);
        $this->task($this->ownerProfile, [
            'title' => 'Overdue task',
            'due_at' => now()->subDay(),
        ]);
        $this->task($this->ownerProfile, [
            'title' => 'Cancelled task',
            'status' => CareTask::STATUS_CANCELLED,
            'due_at' => now()->startOfDay()->addHours(10),
        ]);
        $this->task($this->ownerProfile, [
            'title' => 'Tomorrow task',
            'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson($this->dashboardPath().'?bypass_cache=1')
            ->assertOk()
            ->assertJsonPath('data.tasks_today.visible', true)
            ->assertJsonPath('data.tasks_today.total', 4)
            ->assertJsonPath('data.tasks_today.done', 1)
            ->assertJsonPath('data.tasks_today.overdue', 1);

        $items = $response->json('data.tasks_today.items');

        $this->assertCount(4, $items);

        foreach ($items as $item) {
            $this->assertSame(
                ['due_at', 'id', 'priority', 'profile_display_name', 'status', 'title', 'type', 'version'],
                $this->sortedKeys($item),
                'Task cards must use the P1.3 safe projection only.',
            );
        }

        $body = $response->getContent();
        $this->assertStringNotContainsString('Secret description', $body);
        $this->assertStringNotContainsString('"description"', $body);
        $this->assertStringNotContainsString('"evidence"', $body);
    }

    // -- DASH-T03 -----------------------------------------------------------

    public function test_dash_t03_ring_derivation_red_yellow_green(): void
    {
        Sanctum::actingAs($this->owner);

        $path = $this->dashboardPath().'?bypass_cache=1';

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.family.members.0.status', 'green')
            ->assertJsonPath('data.family.members.0.reason', 'stable')
            // A member must be drill-downable: the filter accepts `profile_id`,
            // so the payload has to expose it next to the person id.
            ->assertJsonPath('data.family.members.0.profile_id', $this->ownerProfile->id)
            ->assertJsonPath('data.family.members.0.person_id', $this->ownerPerson->id);

        $this->task($this->ownerProfile, [
            'title' => 'Pending today',
            'due_at' => now()->startOfDay()->addHours(11),
        ]);

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.family.members.0.status', 'yellow')
            ->assertJsonPath('data.family.members.0.reason', 'tasks_pending');

        $this->measurement($this->ownerProfile, 'blood_pressure', 172, 104);

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.family.members.0.status', 'red')
            ->assertJsonPath('data.family.members.0.reason', 'critical_vital');
    }

    // -- DASH-T04 -----------------------------------------------------------

    public function test_dash_t04_two_members_same_profile_get_different_payloads(): void
    {
        $this->grant(
            $this->caregiver,
            ['profile.basic', 'vitals', 'tasks'],
            ['read'],
            $this->dependentProfile,
        );

        $this->grant(
            $this->limitedMember,
            ['profile.basic'],
            ['read'],
            $this->dependentProfile,
        );

        $this->measurement($this->dependentProfile, 'heart_rate', 72);

        $path = $this->dashboardPath().'?profile_id='.$this->dependentProfile->id.'&bypass_cache=1';

        Sanctum::actingAs($this->caregiver);
        $full = $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.family.visible', true)
            ->assertJsonPath('data.vitals.visible', true)
            ->assertJsonPath('data.tasks_today.visible', true)
            ->json('data');

        Sanctum::actingAs($this->limitedMember);
        $limited = $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.family.visible', true)
            ->assertJsonPath('data.vitals.visible', false)
            ->assertJsonPath('data.tasks_today.visible', false)
            ->json('data');

        $this->assertNotSame($full, $limited);
        $this->assertArrayNotHasKey('cards', $limited['vitals']);
        $this->assertArrayHasKey('cards', $full['vitals']);
    }

    // -- DASH-T05 -----------------------------------------------------------

    public function test_dash_t05_profile_from_another_workspace_is_404(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson($this->dashboardPath().'?profile_id='.$this->otherWorkspaceProfile->id)
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    // -- DASH-T06 -----------------------------------------------------------

    public function test_dash_t06_hidden_cards_leak_no_ids_or_values(): void
    {
        $this->grant($this->limitedMember, ['profile.basic'], ['read'], $this->dependentProfile);
        $this->measurement($this->dependentProfile, 'blood_pressure', 187, 111);
        $this->task($this->dependentProfile, [
            'title' => 'Hidden task title',
            'due_at' => now()->addHour(),
        ]);
        $this->appointment($this->dependentProfile, [
            'title' => 'Hidden appointment title',
            'scheduled_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($this->limitedMember);

        $response = $this->getJson(
            $this->dashboardPath().'?profile_id='.$this->dependentProfile->id.'&bypass_cache=1'
        )->assertOk();

        $data = $response->json('data');

        foreach (['alerts', 'tasks_today', 'vitals', 'next_appointment'] as $card) {
            $this->assertFalse($data[$card]['visible'], "{$card} must be hidden for a profile.basic-only reader.");
            $this->assertSame(['reason', 'visible'], $this->sortedKeys($data[$card]), "{$card} must contain no data fields.");
        }

        $body = $response->getContent();
        $this->assertStringNotContainsString('187', $body);
        $this->assertStringNotContainsString('111', $body);
        $this->assertStringNotContainsString('Hidden task title', $body);
        $this->assertStringNotContainsString('Hidden appointment title', $body);

        $this->assertArrayNotHasKey('cards', $data['vitals']);
        $this->assertArrayNotHasKey('items', $data['tasks_today']);
        $this->assertArrayNotHasKey('id', $data['next_appointment']);
        $this->assertArrayNotHasKey('items', $data['alerts']);
    }

    // -- DASH-T07 -----------------------------------------------------------

    public function test_dash_t07_generated_at_is_iso8601_and_cache_ttl_is_30(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson($this->dashboardPath().'?bypass_cache=1')
            ->assertOk()
            ->assertJsonPath('data.cache_ttl', 30)
            ->assertJsonPath('meta.bypass_cache', true);

        $generatedAt = $response->json('data.generated_at');

        $this->assertIsString($generatedAt);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $generatedAt,
        );
        $this->assertInstanceOf(Carbon::class, Carbon::parse($generatedAt));
    }

    // -- DASH-T08 -----------------------------------------------------------

    public function test_dash_t08_query_count_is_bounded(): void
    {
        $this->grant(
            $this->caregiver,
            ['profile.basic', 'vitals', 'tasks', 'appointments'],
            ['read'],
            $this->dependentProfile,
        );

        $this->measurement($this->dependentProfile, 'blood_pressure', 150, 95);
        $this->measurement($this->dependentProfile, 'heart_rate', 72);
        $this->task($this->dependentProfile, ['title' => 'Task one', 'due_at' => now()->addHour()]);
        $this->task($this->dependentProfile, ['title' => 'Task two', 'due_at' => now()->addHours(2)]);
        $this->appointment($this->dependentProfile, ['title' => 'Visit', 'scheduled_at' => now()->addDay()]);

        Sanctum::actingAs($this->caregiver);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson(
            $this->dashboardPath().'?profile_id='.$this->dependentProfile->id.'&bypass_cache=1'
        )->assertOk();

        $this->assertLessThan(
            25,
            $queries,
            "Dashboard executed {$queries} queries; it must stay bounded (<25) and never become an N+1 trap.",
        );
    }

    // -- Audit chain --------------------------------------------------------

    /**
     * `authorizeBatch` appends one row per card through `recordMany`; the
     * multi-row insert must leave the hash chain continuous, not just be fast.
     */
    public function test_batch_card_authorization_keeps_the_audit_chain_valid(): void
    {
        $this->grant(
            $this->caregiver,
            ['profile.basic', 'vitals', 'tasks', 'appointments'],
            ['read'],
            $this->dependentProfile,
        );

        Sanctum::actingAs($this->caregiver);

        $this->getJson(
            $this->dashboardPath().'?profile_id='.$this->dependentProfile->id.'&bypass_cache=1'
        )->assertOk();

        // The request transaction has committed; re-enter the workspace context
        // so RLS lets the verifier read the segment it just wrote.
        $this->useWorkspace();

        $this->assertGreaterThan(1, DB::table('security_audit_logs')->count());
        $this->assertTrue($this->app->make(SecurityAuditLogger::class)->verifyChain());
    }

    // -- helpers ------------------------------------------------------------

    private function dashboardPath(?Workspace $workspace = null): string
    {
        $workspace ??= $this->workspace;

        return "/api/v1/workspaces/{$workspace->id}/dashboard";
    }

    private function useWorkspace(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($this->tenant->id, $workspace->id);
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $actions
     */
    private function grant(
        User $grantee,
        array $scopes,
        array $actions,
        ?HealthProfile $profile = null,
    ): AccessGrant {
        $profile ??= $this->dependentProfile;
        $this->useWorkspace();

        return AccessGrant::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'granted_by' => $this->owner->id,
            'grantee_user_id' => $grantee->id,
            'resource_scope' => $scopes,
            'allowed_actions' => $actions,
            'purpose' => 'care_coordination',
            'valid_from' => now()->subMinute(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'constraints' => [],
        ]);
    }

    private function measurement(
        HealthProfile $profile,
        string $type,
        float $value,
        ?float $secondary = null,
        ?Carbon $recordedAt = null,
    ): HealthMeasurement {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return HealthMeasurement::query()->create([
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'type' => $type,
            'value' => $value,
            'secondary_value' => $secondary,
            'unit' => $type === 'blood_pressure' ? 'mmHg' : ($type === 'heart_rate' ? 'bpm' : '%'),
            'context' => null,
            'note' => null,
            'recorded_at' => $recordedAt ?? now(),
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'version' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(HealthProfile $profile, array $attributes = []): CareTask
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return CareTask::query()->create(array_merge([
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'created_by' => $this->owner->id,
            'tier' => CareTask::TIER_DELEGATED,
            'type' => 'DAILY_CARE',
            'title' => 'Dashboard task',
            'description' => null,
            'priority' => 'normal',
            'scheduled_for' => null,
            'due_at' => now()->addHour(),
            'timezone' => 'Asia/Bangkok',
            'status' => CareTask::STATUS_OPEN,
            'outcome' => null,
            'verification' => CareTask::VERIFICATION_NOT_REQUIRED,
            'verification_required' => false,
            'evidence_policy' => CareTask::EVIDENCE_NONE,
            'escalation_level' => 0,
            'blocked_reason' => null,
            'completed_at' => null,
            'idempotency_key' => null,
            'version' => 1,
            'source' => 'manual',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function appointment(HealthProfile $profile, array $attributes = []): Appointment
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return Appointment::query()->create(array_merge([
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'created_by' => $this->owner->id,
            'source' => 'manual',
            'title' => 'Follow-up visit',
            'provider_name' => null,
            'facility' => null,
            'purpose' => null,
            'location' => null,
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_PLANNED,
            'cancelled_reason' => null,
            'rescheduled_from_id' => null,
            'transported_by_task_id' => null,
            'prepared_documents' => [],
            'follow_up_task_id' => null,
            'version' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoLabFields(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertStringNotContainsString('lab', $key, "Lab field '{$key}' must never appear (ADR-014).");
                $this->assertStringNotContainsString('mental_health', $key, "Mental-health field '{$key}' must never appear (ADR-014).");
            }

            if (is_array($value)) {
                $this->assertNoLabFields($value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }
}
