<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;
use ZipArchive;

/**
 * DOC-B01..B04 - the document-object backup producer and its drill (gap 4).
 *
 * The nightly workflow covers the database and the corpus; these tests prove
 * the third producer: every `medical_documents` row must have an object in the
 * archive, a missing object fails the run (with the archive kept as evidence),
 * orphan objects are reported, and a non-local disk is refused instead of
 * silently backing up nothing.
 */
class DocumentsBackupTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private User $user;

    private string $profileId;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('documents-backup-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Documents Backup Workspace');

        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'profile_owner');

        TenantContext::set($this->tenant->id, $this->workspace->id);

        $person = Person::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Documents Person',
        ]);

        $profile = HealthProfile::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $person->id,
            'version' => 1,
        ]);

        $this->profileId = $profile->id;

        Storage::fake('local');

        config(['documents.disk' => 'local']);

        $this->output = sys_get_temp_dir().'/lc-docs-'.Uuid7::generate().'.zip';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->output.'*') ?: [] as $path) {
            @unlink($path);
        }

        TenantContext::clear();

        parent::tearDown();
    }

    public function test_doc_b01_the_backup_archives_every_document_and_the_drill_verifies_it(): void
    {
        $document = $this->document();

        $this->artisan('documents:backup', ['--output' => $this->output])->assertSuccessful();

        $this->assertFileExists($this->output);
        $this->assertFileExists($this->output.'.sha256');

        $manifest = $this->manifest();

        $this->assertSame(1, $manifest['rows']);
        $this->assertSame(1, count($manifest['files']));
        $this->assertSame([], $manifest['missing']);
        $this->assertSame([], $manifest['orphans']);
        $this->assertSame('documents/'.$this->tenant->id.'/'.$document->id, $manifest['files'][0]['path']);

        $this->artisan('documents:restore-drill', ['archive' => $this->output])->assertSuccessful();
    }

    public function test_doc_b02_a_row_without_an_object_fails_the_backup_and_the_drill(): void
    {
        $document = $this->document();
        Storage::disk('local')->delete('documents/'.$this->tenant->id.'/'.$document->id);

        $this->artisan('documents:backup', ['--output' => $this->output])->assertFailed();

        // The archive is still written: it is the evidence of what exists.
        $this->assertFileExists($this->output);
        $this->assertContains(
            'documents/'.$this->tenant->id.'/'.$document->id,
            $this->manifest()['missing'],
        );

        $this->artisan('documents:restore-drill', ['archive' => $this->output])->assertFailed();
    }

    public function test_doc_b03_an_orphan_object_is_reported_but_does_not_fail_the_run(): void
    {
        Storage::disk('local')->put('documents/'.$this->tenant->id.'/orphan-object', 'orphan-bytes');

        $this->artisan('documents:backup', ['--output' => $this->output])->assertSuccessful();

        $manifest = $this->manifest();

        $this->assertSame(0, $manifest['rows']);
        $this->assertSame([], $manifest['missing']);
        $this->assertContains('documents/'.$this->tenant->id.'/orphan-object', $manifest['orphans']);
    }

    public function test_doc_b04_a_non_local_disk_is_refused(): void
    {
        config(['documents.disk' => 's3']);

        $this->artisan('documents:backup', ['--output' => $this->output])->assertFailed();

        $this->assertFileDoesNotExist($this->output);
    }

    private function document(): MedicalDocument
    {
        $id = Uuid7::generate();
        $path = 'documents/'.$this->tenant->id.'/'.$id;

        Storage::disk('local')->put($path, 'document-bytes-'.$id);

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

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->output) === true, 'The archive must open.');

        try {
            $raw = $zip->getFromName('manifest.json');
        } finally {
            $zip->close();
        }

        $this->assertIsString($raw);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
