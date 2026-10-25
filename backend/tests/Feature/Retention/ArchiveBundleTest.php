<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Retention\Services\ArchiveBundleService;
use App\Modules\Retention\Services\ArchiveManifestService;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-B01..B04 - the encrypted archive bundle and its drill (P4b-1).
 *
 * The bundle is content, not counts: it must be encrypted at rest (the raw
 * ciphertext must not contain the value), verifiable (hash + decrypt + counts),
 * and the drill must fail loudly when any of those three is broken.
 */
class ArchiveBundleTest extends TestCase
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

        Storage::fake('local');

        $this->tenant = $this->createTenant('archive-bundle-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Archive Bundle Workspace');

        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'profile_owner');

        TenantContext::set($this->tenant->id, $this->workspace->id);

        $person = Person::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Bundle Person',
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

    public function test_ret_b01_archiving_writes_an_encrypted_bundle_that_verifies(): void
    {
        $this->measurement();
        $this->document();

        $manifest = $this->archive();

        $this->assertNotNull($manifest->archive_path);
        $this->assertSame(64, strlen((string) $manifest->archive_sha256));
        Storage::disk('local')->assertExists($manifest->archive_path);

        $cipher = (string) Storage::disk('local')->get($manifest->archive_path);
        // The raw file is ciphertext: neither the value nor the person's name
        // appears in it.
        $this->assertStringNotContainsString('70.5', $cipher);
        $this->assertStringNotContainsString('Bundle Person', $cipher);

        $result = app(ArchiveBundleService::class)->verify($manifest);

        $this->assertTrue($result['ok'], implode('; ', $result['problems']));
        $this->assertSame(1, $result['counts']['persons']);
        $this->assertSame(1, $result['counts']['health_measurements']);
        $this->assertSame(1, $result['counts']['medical_documents']);
        $this->assertTrue(app(ArchiveManifestService::class)->verify($manifest));
    }

    public function test_ret_b02_the_drill_passes_and_fails_after_the_file_is_tampered_with(): void
    {
        $this->measurement();
        $manifest = $this->archive();

        $this->artisan('retention:archive-drill', ['workspace' => $this->workspace->id])
            ->expectsOutputToContain('Bundle verifies: yes')
            ->assertSuccessful();

        Storage::disk('local')->put(
            (string) $manifest->archive_path,
            (string) Storage::disk('local')->get($manifest->archive_path).'tampered',
        );

        $this->artisan('retention:archive-drill', ['workspace' => $this->workspace->id])
            ->expectsOutputToContain('Bundle verifies: NO')
            ->assertFailed();
    }

    public function test_ret_b03_a_missing_bundle_is_reported(): void
    {
        $this->measurement();
        $manifest = $this->archive();

        Storage::disk('local')->delete((string) $manifest->archive_path);

        $result = app(ArchiveBundleService::class)->verify($manifest->fresh());

        $this->assertFalse($result['ok']);
        $this->assertContains('the archive bundle file is missing', $result['problems']);

        $this->artisan('retention:archive-drill', ['workspace' => $this->workspace->id])->assertFailed();
    }

    public function test_ret_b04_a_count_mismatch_between_bundle_and_inventory_is_reported(): void
    {
        $this->measurement();
        $manifest = $this->archive();

        $counts = $manifest->counts;
        $counts['health_measurements'] = 99;
        $manifest->forceFill(['counts' => $counts])->save();

        $result = app(ArchiveBundleService::class)->verify($manifest->fresh());

        $this->assertFalse($result['ok']);
        $this->assertTrue(
            collect($result['problems'])->contains(fn (string $problem): bool => str_contains($problem, 'health_measurements')),
            implode('; ', $result['problems']),
        );
    }

    private function archive(): WorkspaceArchiveManifest
    {
        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);

        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());

        return WorkspaceArchiveManifest::query()->where('workspace_id', $this->workspace->id)->sole();
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
