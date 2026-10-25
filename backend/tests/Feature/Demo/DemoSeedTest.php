<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * DEMO-T01..T05 - the demo:seed command (TASK DEPLOY-2).
 *
 * These tests assert the synthetic fixture shape, its idempotency, the narrow
 * reset scope and the production guard. The authorization assertions are the
 * important part: the demo must demonstrate the model, not merely look full.
 */
class DemoSeedTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    // -- DEMO-T01 -----------------------------------------------------------

    public function test_demo_t01_seed_creates_the_workspace_members_profile_and_data_volumes(): void
    {
        $exit = Artisan::call('demo:seed', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Synthetic data only', $output);
        $this->assertStringContainsString('demo.owner@example.test', $output);

        $workspace = $this->demoWorkspace();
        $tenant = $this->demoTenant($workspace);

        $this->assertStringStartsWith('demo-', $workspace->slug);
        $this->assertTrue((bool) ($workspace->settings['demo_seed'] ?? false));
        $this->assertSame(1, (int) ($workspace->settings['demo_version'] ?? 0));

        $counts = $this->demoCounts($tenant, $workspace);

        $this->assertSame(3, $counts['members'], 'owner + caregiver + viewer');
        $this->assertSame(4, $counts['persons'], 'three caregivers plus the elder');
        $this->assertSame(1, $counts['profiles'], 'one elder health profile');

        $this->assertSame(180, $counts['measurements']);
        $this->assertSame(90, $counts['blood_pressure']);
        $this->assertSame(90, $counts['blood_glucose']);
        $this->assertSame(2, $counts['conditions']);
        $this->assertSame(1, $counts['allergies']);

        $this->assertSame(3, $counts['medication_plans']);
        $this->assertSame(200, $counts['medication_records']);

        $this->assertSame(12, $counts['appointments']);
        $this->assertSame(2, $counts['appointments_upcoming']);

        $this->assertSame(25, $counts['care_tasks']);
        $this->assertSame(52, $counts['task_events']);

        $this->assertSame(3, $counts['documents']);
        $this->assertSame(3, $counts['grants']);
        $this->assertSame(3, $counts['consents']);

        $this->assertSame(3, User::query()->whereIn('email', [
            'demo.owner@example.test',
            'demo.caregiver@example.test',
            'demo.viewer@example.test',
        ])->count());

        $elder = TenantContext::run(
            $tenant->id,
            $workspace->id,
            fn (): ?Person => Person::query()
                ->where('workspace_id', $workspace->id)
                ->where('display_name', 'คุณยาย ทดสอบ')
                ->first(),
        );

        $this->assertInstanceOf(Person::class, $elder);
        $this->assertSame('1948-05-12', $elder->birth_date?->toDateString());

        $profile = TenantContext::run(
            $tenant->id,
            $workspace->id,
            fn (): ?HealthProfile => HealthProfile::query()->where('person_id', $elder->id)->first(),
        );

        $this->assertInstanceOf(HealthProfile::class, $profile);
        $this->assertSame($workspace->id, $profile->workspace_id);
    }

    // -- DEMO-T02 -----------------------------------------------------------

    public function test_demo_t02_running_twice_does_not_duplicate_rows(): void
    {
        $this->assertSame(0, Artisan::call('demo:seed', ['--force' => true]));

        $workspace = $this->demoWorkspace();
        $tenant = $this->demoTenant($workspace);
        $first = $this->demoCounts($tenant, $workspace);

        $this->assertSame(0, Artisan::call('demo:seed', ['--force' => true]));

        $this->assertSame(1, $this->demoWorkspaceCount(), 'a second run must reuse the same demo workspace');

        $second = $this->demoCounts($tenant, $workspace);

        $this->assertSame($first, $second, 'the second run must add nothing');
        $this->assertSame(180, $second['measurements']);
        $this->assertSame(200, $second['medication_records']);
        $this->assertSame(25, $second['care_tasks']);
        $this->assertSame(52, $second['task_events']);
    }

    // -- DEMO-T03 -----------------------------------------------------------

    public function test_demo_t03_reset_removes_only_the_demo_workspaces_rows(): void
    {
        $this->assertSame(0, Artisan::call('demo:seed', ['--force' => true]));

        $demoWorkspace = $this->demoWorkspace();
        $demoTenant = $this->demoTenant($demoWorkspace);

        $profileId = TenantContext::run(
            $demoTenant->id,
            $demoWorkspace->id,
            fn (): string => (string) DB::table('health_profiles')
                ->where('workspace_id', $demoWorkspace->id)
                ->value('id'),
        );

        $manualDemoId = Uuid7::generate();

        TenantContext::run($demoTenant->id, $demoWorkspace->id, function () use (
            $demoTenant,
            $demoWorkspace,
            $profileId,
            $manualDemoId,
        ): void {
            DB::table('health_measurements')->insert([
                'id' => $manualDemoId,
                'tenant_id' => $demoTenant->id,
                'workspace_id' => $demoWorkspace->id,
                'profile_id' => $profileId,
                'type' => 'weight',
                'value' => 61.5,
                'secondary_value' => null,
                'unit' => 'kg',
                'context' => null,
                'note' => null,
                'recorded_at' => now(),
                'recorded_by' => null,
                'source' => 'manual',
                'version' => 1,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // A second workspace is the control: reset must not touch it.
        $controlTenant = $this->createTenant('demo-control-tenant');
        $controlWorkspace = $this->createWorkspace($controlTenant, 'Demo Control Workspace');
        $controlPersonId = Uuid7::generate();
        $controlProfileId = Uuid7::generate();
        $controlMeasurementId = Uuid7::generate();

        TenantContext::run($controlTenant->id, $controlWorkspace->id, function () use (
            $controlTenant,
            $controlWorkspace,
            $controlPersonId,
            $controlProfileId,
            $controlMeasurementId,
        ): void {
            DB::table('persons')->insert([
                'id' => $controlPersonId,
                'tenant_id' => $controlTenant->id,
                'workspace_id' => $controlWorkspace->id,
                'user_id' => null,
                'display_name' => 'Control Person',
                'birth_date' => null,
                'managed_by_person_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('health_profiles')->insert([
                'id' => $controlProfileId,
                'tenant_id' => $controlTenant->id,
                'workspace_id' => $controlWorkspace->id,
                'person_id' => $controlPersonId,
                'managed_by_person_id' => null,
                'version' => 1,
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('health_measurements')->insert([
                'id' => $controlMeasurementId,
                'tenant_id' => $controlTenant->id,
                'workspace_id' => $controlWorkspace->id,
                'profile_id' => $controlProfileId,
                'type' => 'weight',
                'value' => 70.0,
                'secondary_value' => null,
                'unit' => 'kg',
                'context' => null,
                'note' => null,
                'recorded_at' => now(),
                'recorded_by' => null,
                'source' => 'manual',
                'version' => 1,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $exit = Artisan::call('demo:seed', ['--reset' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        $counts = $this->demoCounts($demoTenant, $demoWorkspace);

        $this->assertSame(180, $counts['measurements'], 'reset reseeds, it does not append');
        $this->assertSame(25, $counts['care_tasks']);
        $this->assertSame(52, $counts['task_events']);
        $this->assertSame(3, $counts['grants']);
        $this->assertSame(3, $counts['consents'], 'consent history is append-only and is not duplicated');

        $this->assertFalse(
            TenantContext::run(
                $demoTenant->id,
                $demoWorkspace->id,
                fn (): bool => DB::table('health_measurements')->where('id', $manualDemoId)->exists(),
            ),
            'a manual row inside the demo workspace is removed by --reset',
        );

        $this->assertTrue(
            TenantContext::run(
                $controlTenant->id,
                $controlWorkspace->id,
                fn (): bool => DB::table('health_measurements')->where('id', $controlMeasurementId)->exists(),
            ),
            'the control workspace must be untouched',
        );

        $this->assertSame(
            1,
            TenantContext::run(
                $controlTenant->id,
                $controlWorkspace->id,
                fn (): int => DB::table('health_measurements')->where('workspace_id', $controlWorkspace->id)->count(),
            ),
        );
    }

    // -- DEMO-T04 -----------------------------------------------------------

    public function test_demo_t04_it_refuses_to_run_with_app_env_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $exit = Artisan::call('demo:seed', ['--force' => true]);
            $output = Artisan::output();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('APP_ENV=production', $output);
        $this->assertSame(
            0,
            $this->demoWorkspaceCount(),
            'the refusal must happen before any demo row is written',
        );
    }

    // -- DEMO-T05 -----------------------------------------------------------

    public function test_demo_t05_the_caregiver_grant_excludes_mental_health_and_lab_results(): void
    {
        $this->assertSame(0, Artisan::call('demo:seed', ['--force' => true]));

        $workspace = $this->demoWorkspace();
        $tenant = $this->demoTenant($workspace);

        $caregiver = User::query()->where('email', 'demo.caregiver@example.test')->firstOrFail();
        $owner = User::query()->where('email', 'demo.owner@example.test')->firstOrFail();
        $viewer = User::query()->where('email', 'demo.viewer@example.test')->firstOrFail();

        $grants = TenantContext::run(
            $tenant->id,
            $workspace->id,
            fn () => AccessGrant::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', 'active')
                ->get(),
        );

        $this->assertCount(3, $grants);

        $byGrantee = $grants->keyBy('grantee_user_id');

        $caregiverGrant = $byGrantee->get($caregiver->id);
        $this->assertInstanceOf(AccessGrant::class, $caregiverGrant);
        $this->assertContains('vitals', $caregiverGrant->resource_scope);
        $this->assertContains('medications', $caregiverGrant->resource_scope);
        $this->assertContains('tasks', $caregiverGrant->resource_scope);
        $this->assertContains('profile.basic', $caregiverGrant->resource_scope);
        $this->assertNotContains('mental_health', $caregiverGrant->resource_scope);
        $this->assertNotContains('lab_results', $caregiverGrant->resource_scope);

        $ownerGrant = $byGrantee->get($owner->id);
        $this->assertInstanceOf(AccessGrant::class, $ownerGrant);
        $this->assertContains('vitals', $ownerGrant->resource_scope);
        $this->assertContains('medications', $ownerGrant->resource_scope);
        $this->assertNotContains('mental_health', $ownerGrant->resource_scope);
        $this->assertNotContains('lab_results', $ownerGrant->resource_scope);

        $viewerGrant = $byGrantee->get($viewer->id);
        $this->assertInstanceOf(AccessGrant::class, $viewerGrant);
        $this->assertSame(['profile.basic'], $viewerGrant->resource_scope);
        $this->assertSame(['read'], $viewerGrant->allowed_actions);
    }

    // -- helpers ------------------------------------------------------------

    private function demoWorkspace(): Workspace
    {
        foreach ($this->demoTenants() as $tenant) {
            $workspace = TenantContext::run(
                $tenant->id,
                '',
                fn (): ?Workspace => Workspace::query()
                    ->whereRaw("settings->>'demo_seed' = 'true'")
                    ->first(),
            );

            if ($workspace instanceof Workspace) {
                return $workspace;
            }
        }

        $this->fail('No demo workspace was found.');
    }

    private function demoWorkspaceCount(): int
    {
        $count = 0;

        foreach ($this->demoTenants() as $tenant) {
            $count += TenantContext::run(
                $tenant->id,
                '',
                fn (): int => Workspace::query()
                    ->whereRaw("settings->>'demo_seed' = 'true'")
                    ->count(),
            );
        }

        return $count;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function demoTenants()
    {
        return Tenant::query()
            ->where('slug', 'like', 'demo-%')
            ->where('name', 'like', '%DEMO%')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function demoTenant(Workspace $workspace): Tenant
    {
        return Tenant::query()->findOrFail($workspace->tenant_id);
    }

    /**
     * @return array<string, int>
     */
    private function demoCounts(Tenant $tenant, Workspace $workspace): array
    {
        return TenantContext::run($tenant->id, $workspace->id, function () use ($workspace): array {
            $workspaceId = $workspace->id;
            $now = now();

            return [
                'members' => DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->count(),
                'persons' => DB::table('persons')->where('workspace_id', $workspaceId)->count(),
                'profiles' => DB::table('health_profiles')->where('workspace_id', $workspaceId)->count(),
                'measurements' => DB::table('health_measurements')->where('workspace_id', $workspaceId)->count(),
                'blood_pressure' => DB::table('health_measurements')
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'blood_pressure')
                    ->count(),
                'blood_glucose' => DB::table('health_measurements')
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'blood_glucose')
                    ->count(),
                'conditions' => DB::table('health_conditions')->where('workspace_id', $workspaceId)->count(),
                'allergies' => DB::table('allergies')->where('workspace_id', $workspaceId)->count(),
                'medication_plans' => DB::table('medication_plans')->where('workspace_id', $workspaceId)->count(),
                'medication_records' => DB::table('medication_records')->where('workspace_id', $workspaceId)->count(),
                'appointments' => DB::table('appointments')->where('workspace_id', $workspaceId)->count(),
                'appointments_upcoming' => DB::table('appointments')
                    ->where('workspace_id', $workspaceId)
                    ->where('scheduled_at', '>=', $now)
                    ->whereIn('status', ['planned', 'confirmed'])
                    ->count(),
                'care_tasks' => DB::table('care_tasks')->where('workspace_id', $workspaceId)->count(),
                'task_events' => DB::table('task_events')->where('workspace_id', $workspaceId)->count(),
                'documents' => DB::table('medical_documents')->where('workspace_id', $workspaceId)->count(),
                'grants' => DB::table('access_grants')->where('workspace_id', $workspaceId)->count(),
                'consents' => DB::table('consent_records')->where('workspace_id', $workspaceId)->count(),
                'timeline_entries' => DB::table('timeline_entries')->where('workspace_id', $workspaceId)->count(),
            ];
        });
    }
}
