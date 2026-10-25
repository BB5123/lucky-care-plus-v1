<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Retention\Services\ArchiveManifestService;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWorkspaces;
use Tests\Concerns\SetsWorkspaceState;
use Tests\TestCase;

/**
 * RET-A01..A04 - the archive inventory written when an account is archived (P4a).
 *
 * Counts only, hashed over the workspace id + archive instant + counts. The
 * tests pin the three things that matter: the manifest appears exactly when the
 * state becomes `archived`, its counts match the fixture, and changing it after
 * the fact makes verification fail.
 */
class ArchiveManifestTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;
    use SetsWorkspaceState;

    private Tenant $tenant;

    private Workspace $workspace;

    private User $user;

    private string $profileId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('archive-manifest-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Archive Manifest Workspace');

        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'profile_owner');

        TenantContext::set($this->tenant->id, $this->workspace->id);

        $person = Person::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Archive Person',
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

    public function test_ret_a01_entering_archived_writes_one_manifest_with_counts(): void
    {
        $this->freezeTime();
        $this->measurement();
        $this->document();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);

        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());

        $this->assertSame(RetentionState::Archived, $this->workspace->fresh()->retention_state);

        $manifest = WorkspaceArchiveManifest::query()->where('workspace_id', $this->workspace->id)->sole();

        $this->assertSame(1, $manifest->counts['health_measurements']);
        $this->assertSame(1, $manifest->counts['medical_documents']);
        $this->assertSame(0, $manifest->counts['care_tasks']);
        $this->assertSame(64, strlen($manifest->sha256));
        $this->assertTrue(app(ArchiveManifestService::class)->verify($manifest));

        $this->assertSame(1, DB::table('security_audit_logs')
            ->where('action', 'retention.archive_manifest')
            ->where('result', 'allow')
            ->count());
    }

    public function test_ret_a02_the_command_prints_the_manifest_and_fails_after_tampering(): void
    {
        $this->freezeTime();
        $this->measurement();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);
        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());

        $this->artisan('retention:archive-manifest', ['workspace' => $this->workspace->id])
            ->expectsOutputToContain('Hash verifies: yes')
            ->assertSuccessful();

        $manifest = WorkspaceArchiveManifest::query()->where('workspace_id', $this->workspace->id)->sole();
        $counts = $manifest->counts;
        $counts['health_measurements'] = 99;
        $manifest->forceFill(['counts' => $counts])->save();

        $this->assertFalse(app(ArchiveManifestService::class)->verify($manifest->fresh()));

        $this->artisan('retention:archive-manifest', ['workspace' => $this->workspace->id])
            ->assertFailed();
    }

    public function test_ret_a03_a_restore_keeps_the_manifest_and_a_second_lapse_appends_one(): void
    {
        $this->freezeTime();
        $this->measurement();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);
        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());

        $lifecycle->restore($this->workspace->fresh());

        $this->assertSame(1, WorkspaceArchiveManifest::query()->where('workspace_id', $this->workspace->id)->count());

        $lifecycle->lapse($this->workspace->fresh());
        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());

        $this->assertSame(2, WorkspaceArchiveManifest::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_ret_a04_read_only_and_active_accounts_have_no_manifest(): void
    {
        $this->freezeTime();
        $this->measurement();

        $this->assertSame(0, WorkspaceArchiveManifest::query()->count());

        app(WorkspaceLifecycle::class)->lapse($this->workspace);

        $this->assertSame(RetentionState::ReadOnly, $this->workspace->fresh()->retention_state);
        $this->assertSame(0, WorkspaceArchiveManifest::query()->count());

        $this->artisan('retention:archive-manifest', ['workspace' => $this->workspace->id])
            ->assertFailed();
    }

    public function test_ret_a05_the_json_output_is_machine_readable(): void
    {
        $this->freezeTime();
        $this->measurement();

        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);
        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());

        $exit = Artisan::call('retention:archive-manifest', [
            'workspace' => $this->workspace->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame($this->workspace->id, $payload['workspace_id']);
        $this->assertSame(1, $payload['counts']['health_measurements']);
        $this->assertSame(array_sum($payload['counts']), $payload['rows']);
        $this->assertTrue($payload['verified']);
        $this->assertNotNull($payload['archived_at']);
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

    private function document(): MedicalDocument
    {
        $id = Uuid7::generate();
        $path = 'documents/'.$this->tenant->id.'/'.$id;

        Storage::fake('local');
        Storage::disk('local')->put($path, 'archive-document');

        return MedicalDocument::query()->forceCreate([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profileId,
            'uploaded_by' => $this->user->id,
            'kind' => 'lab_result',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'result.pdf',
            'scan_status' => MedicalDocument::SCAN_SKIPPED,
            'status' => MedicalDocument::STATUS_AVAILABLE,
            'version' => 1,
        ]);
    }
}
