<?php

namespace Tests\Feature\Documents;

use App\Modules\Audit\Models\SecurityAuditLog;
use App\Modules\Documents\Application\DocumentService;
use App\Modules\Documents\Infrastructure\Scanning\MalwareScanner;
use App\Modules\Documents\Infrastructure\Scanning\ScanVerdict;
use App\Modules\Documents\Jobs\DeleteDocumentObject;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Retention\Models\DataHold;
use App\Modules\Retention\Services\LegalHold;
use App\Modules\Retention\Services\PurgeService;
use App\Support\Id\Uuid7;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * DOC-T01..DOC-T12 — the private medical-document pipeline (ADR-014).
 *
 * The suite exercises the HTTP boundary where the boundary matters
 * (permissions, consent, signed capability links, storage layout, scan
 * fail-closed) and the domain services for retention and evidence integration.
 */
class DocumentTest extends DocumentTestCase
{
    public function test_doc_t01_upload_stores_the_object_under_an_opaque_key_and_returns_metadata(): void
    {
        Sanctum::actingAs($this->owner);

        $document = $this->uploadPdf([
            'kind' => 'discharge_summary',
            'captured_at' => now()->subDay()->toIso8601String(),
        ]);

        $bytes = $this->pdfBytes();

        $this->assertSame('discharge_summary', $document['kind']);
        $this->assertSame('application/pdf', $document['mime_type']);
        $this->assertSame(strlen($bytes), $document['size_bytes']);
        $this->assertSame(hash('sha256', $bytes), $document['sha256']);
        $this->assertSame(MedicalDocument::SCAN_SKIPPED, $document['scan_status']);
        $this->assertSame(MedicalDocument::STATUS_AVAILABLE, $document['status']);
        $this->assertSame(1, $document['version']);
        $this->assertNotNull($document['captured_at']);
        $this->assertArrayNotHasKey('storage_path', $document);
        $this->assertArrayNotHasKey('storage_disk', $document);
        $this->assertArrayNotHasKey('original_name', $document);

        $this->useWorkspace();
        $stored = MedicalDocument::query()->findOrFail($document['id']);

        // Exactly `documents/{tenant_uuid}/{document_uuid}`: no profile id,
        // user filename, extension, or PHI (N-02).
        $this->assertSame(
            'documents/'.$this->tenant->id.'/'.$stored->id,
            $stored->storage_path,
        );
        $this->assertStringNotContainsString($this->profile->id, $stored->storage_path);
        $this->assertStringNotContainsString('scan.pdf', $stored->storage_path);
        $this->assertStringNotContainsString('.pdf', $stored->storage_path);
        Storage::disk('local')->assertExists($stored->storage_path);

        // The filename is encrypted at rest even though it is returned on a
        // read-authorized endpoint.
        $rawOriginalName = DB::table('medical_documents')
            ->where('id', $document['id'])
            ->value('original_name');

        $this->assertIsString($rawOriginalName);
        $this->assertNotSame('scan.pdf', $rawOriginalName);
        $this->assertSame('scan.pdf', $stored->original_name);

        $show = $this->getJson($this->documentsPath(null, null, $document['id']))
            ->assertOk()
            ->assertJsonPath('data.original_name', 'scan.pdf')
            ->assertJsonPath('data.kind', 'discharge_summary');

        $this->assertArrayNotHasKey('storage_path', $show->json('data'));

        $list = $this->getJson($this->documentsPath())->assertOk();
        $this->assertSame([$document['id']], array_column($list->json('data'), 'id'));
        $this->assertSame('scan.pdf', $list->json('data.0.original_name'));
    }

    public function test_doc_t02_a_spoofed_client_content_type_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        // PDF bytes behind a .png name: the client claims image/png but the
        // sniffed bytes are application/pdf.
        $file = UploadedFile::fake()
            ->createWithContent('scan.png', $this->pdfBytes())
            ->mimeType('image/png');

        $this->post(
            $this->documentsPath(),
            $this->uploadPayload(['file' => $file]),
            ['Accept' => 'application/json'],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonPath(
                'errors.file.0',
                'The declared file type does not match the file contents.',
            );

        $this->useWorkspace();
        $this->assertSame(0, MedicalDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_doc_t03_a_file_over_max_bytes_is_rejected_with_the_limit_named(): void
    {
        $maxBytes = 64;
        config(['documents.max_bytes' => $maxBytes]);

        $this->assertGreaterThan($maxBytes, strlen($this->pdfBytes()));

        Sanctum::actingAs($this->owner);

        $this->post(
            $this->documentsPath(),
            $this->uploadPayload(),
            ['Accept' => 'application/json'],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonPath('errors.file.0', 'ไฟล์ต้องมีขนาดไม่เกิน '.$maxBytes.' ไบต์');

        $this->useWorkspace();
        $this->assertSame(0, MedicalDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_doc_t04_the_signed_url_works_and_expires_after_the_ttl(): void
    {
        Sanctum::actingAs($this->owner);

        $document = $this->uploadPdf();
        $this->consent('data_export');

        $response = $this->postJson($this->documentUrlPath($document['id']))
            ->assertOk();

        $url = (string) $response->json('data.url');
        $expiresAt = Carbon::parse((string) $response->json('data.expires_at'));

        $this->assertStringContainsString('/api/v1/documents/'.$document['id'].'/download', $url);
        $this->assertTrue($expiresAt->lessThanOrEqualTo(now()->addMinutes(5)->addSecond()));
        $this->assertTrue($expiresAt->greaterThan(now()->addMinutes(4)));

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload('document-'.$document['id'].'.pdf');

        // The signature is time-bounded: after the TTL the same link fails.
        $this->travel(6)->minutes();

        $this->get($url)
            ->assertStatus(403)
            ->assertJsonPath('code', 'invalid_signature');
    }

    public function test_doc_t05_the_download_route_rechecks_authorization_not_only_the_signature(): void
    {
        Sanctum::actingAs($this->owner);

        $document = $this->uploadPdf();
        $this->consent('data_export');

        $url = (string) $this->postJson($this->documentUrlPath($document['id']))
            ->assertOk()
            ->json('data.url');

        // A valid signature is not authorization: an active member without a
        // documents grant is still denied.
        Sanctum::actingAs($this->caregiver);
        $this->get($url)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->grant($this->caregiver, ['documents'], ['export']);

        Sanctum::actingAs($this->caregiver);
        $this->get($url)->assertOk();
    }

    public function test_doc_t06_metadata_and_url_issuance_each_write_one_audit_row(): void
    {
        Sanctum::actingAs($this->owner);

        $document = $this->uploadPdf();
        $this->consent('data_export');

        $this->useWorkspace();
        $before = DB::table('security_audit_logs')->count();

        $this->getJson($this->documentsPath(null, null, $document['id']))
            ->assertOk();

        $this->useWorkspace();
        $this->assertSame($before + 1, DB::table('security_audit_logs')->count());
        // The authorization ledger records the profile the decision was about;
        // the document id is intentionally not an authorization target.
        $this->assertDatabaseHas('security_audit_logs', [
            'action' => 'medical_document.read',
            'target_type' => 'health_profile',
            'target_id' => $this->profile->id,
            'result' => 'allow',
        ]);

        $this->postJson($this->documentUrlPath($document['id']))
            ->assertOk();

        $this->useWorkspace();
        $this->assertSame($before + 2, DB::table('security_audit_logs')->count());
        $this->assertDatabaseHas('security_audit_logs', [
            'action' => 'medical_document.export',
            'target_type' => 'health_profile',
            'target_id' => $this->profile->id,
            'result' => 'allow',
        ]);
    }

    public function test_doc_t07_a_viewer_without_the_scope_gets_403_and_another_workspace_gets_404(): void
    {
        Sanctum::actingAs($this->viewer);

        // Matrix A allows a viewer to read documents only conditionally; a
        // missing documents grant is a denial, not an empty list.
        $this->getJson($this->documentsPath())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->grant($this->viewer, ['documents'], ['read']);
        $this->getJson($this->documentsPath())->assertOk();

        // A route pointing at another workspace's profile never confirms the
        // profile exists.
        Sanctum::actingAs($this->owner);

        $this->getJson($this->documentsPath($this->otherWorkspaceProfile))
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    public function test_doc_t08_delete_is_denied_by_baseline_and_the_service_path_is_audited(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->owner);

        $document = $this->uploadPdf();

        // The catalogue says "Delete where permitted -> Default denied; retention
        // rules apply", and ABD-011 denies health-record deletion by default. The
        // route therefore exists (the id is catalogued) but always refuses, exactly
        // like DELETE /measurements.
        $this->deleteJson($this->documentsPath(null, null, $document['id']))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->useWorkspace();
        $survivor = MedicalDocument::withTrashed()->findOrFail($document['id']);

        $this->assertNull($survivor->deleted_at);
        $this->assertSame(MedicalDocument::STATUS_AVAILABLE, $survivor->status);
        Storage::disk('local')->assertExists($survivor->storage_path);

        // The domain path stays available to an approved workflow: soft delete,
        // audit, then a queued object removal.
        app(DocumentService::class)
            ->delete($this->workspace, $this->profile, $document['id'], $this->owner);

        $stored = MedicalDocument::withTrashed()->findOrFail($document['id']);

        $this->assertNotNull($stored->deleted_at);
        $this->assertSame(MedicalDocument::STATUS_DELETED, $stored->status);
        $this->assertSame(2, $stored->version);

        // The request never deletes bytes inline; the queued job owns that.
        Storage::disk('local')->assertExists($stored->storage_path);

        Queue::assertPushed(
            DeleteDocumentObject::class,
            fn (DeleteDocumentObject $job): bool => $job->documentId === $document['id']
                && $job->tenantId === $this->tenant->id
                && $job->workspaceId === $this->workspace->id,
        );

        // Running the queued job removes the object but keeps the metadata for
        // the retention clock.
        (new DeleteDocumentObject(
            $document['id'],
            $this->tenant->id,
            $this->workspace->id,
        ))->handle();

        Storage::disk('local')->assertMissing($stored->storage_path);
        $this->assertDatabaseHas('medical_documents', ['id' => $document['id']]);

        $list = $this->getJson($this->documentsPath())->assertOk();
        $this->assertSame([], $list->json('data'));

        $this->useWorkspace();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'document.delete',
            'target_id' => $document['id'],
        ]);
    }

    public function test_doc_t09_attaching_another_profiles_document_is_422(): void
    {
        Sanctum::actingAs($this->owner);

        $foreign = $this->makeDocument($this->dependentProfile);
        $task = $this->createPhotoTask();

        $this->postJson(
            $this->careTaskPath($task['id']).'/evidence',
            ['type' => 'photo', 'document_id' => $foreign->id],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonPath(
                'errors.document_id.0',
                'The document is not available for this task profile.',
            );
    }

    public function test_doc_t10_photo_policy_completion_requires_and_accepts_a_document(): void
    {
        Sanctum::actingAs($this->owner);

        $document = $this->uploadPdf();
        $happy = $this->createPhotoTask();

        Sanctum::actingAs($this->caregiver);
        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->careTaskPath($happy['id']).'/start')
            ->assertOk();

        $this->postJson(
            $this->careTaskPath($happy['id']).'/evidence',
            ['type' => 'photo', 'document_id' => $document['id']],
        )->assertCreated();

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->careTaskPath($happy['id']).'/complete', ['outcome' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        Sanctum::actingAs($this->owner);
        $missing = $this->createPhotoTask();

        Sanctum::actingAs($this->caregiver);
        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->careTaskPath($missing['id']).'/start')
            ->assertOk();

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->careTaskPath($missing['id']).'/complete', ['outcome' => 'done'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonPath(
                'errors.evidence.0',
                'Evidence matching the task policy is required before completion.',
            );
    }

    public function test_doc_t11_a_quarantined_document_cannot_be_attached(): void
    {
        Sanctum::actingAs($this->owner);

        $quarantined = $this->makeDocument($this->profile, [
            'status' => MedicalDocument::STATUS_QUARANTINED,
        ]);

        $task = $this->createPhotoTask();

        $this->postJson(
            $this->careTaskPath($task['id']).'/evidence',
            ['type' => 'photo', 'document_id' => $quarantined->id],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonPath(
                'errors.document_id.0',
                'The document is not available for this task profile.',
            );
    }

    /**
     * An infected upload must be rejected **and** leave a trail.
     *
     * The infected path had no HTTP-level test at all before 2026-10-25, which is
     * why a reviewer (me) could suspect the deny row was lost to the request
     * transaction. It is not: the row is readable immediately after the 422, and
     * this test now pins that down.
     */
    public function test_doc_t13_infected_upload_is_rejected_and_audited(): void
    {
        config(['documents.scan_required' => true]);

        $this->app->bind(MalwareScanner::class, fn (): MalwareScanner => new class implements MalwareScanner
        {
            public function scan(string $absolutePath): ScanVerdict
            {
                return ScanVerdict::infected('clamav', 'Eicar-Test-Signature');
            }

            public function name(): string
            {
                return 'clamav';
            }
        });

        Sanctum::actingAs($this->owner);

        $this->post(
            $this->documentsPath(),
            $this->uploadPayload(),
            ['Accept' => 'application/json'],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'document_infected');

        $this->useWorkspace();

        $this->assertSame(0, MedicalDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The deny row is written synchronously, inside the request: the audit trail
        // must be readable immediately after the rejection, not only once something
        // else flushes. There was no test for the infected path before this one.
        $this->assertSame(1, SecurityAuditLog::query()->where('action', 'document.scan_infected')->count());
        $this->assertSame('deny', SecurityAuditLog::query()->where('action', 'document.scan_infected')->value('result'));
    }

    public function test_doc_t12_required_scanning_fails_closed_and_stores_nothing(): void
    {
        config(['documents.scan_required' => true]);

        Sanctum::actingAs($this->owner);

        $this->post(
            $this->documentsPath(),
            $this->uploadPayload(),
            ['Accept' => 'application/json'],
        )
            ->assertStatus(503)
            ->assertJsonPath('code', 'scan_unavailable');

        $this->useWorkspace();

        $this->assertSame(0, MedicalDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_retention_removes_the_object_then_the_row_and_respects_holds(): void
    {
        config(['retention.dry_run' => false]);

        $this->useWorkspace();

        $old = $this->makeDocument($this->profile, [
            'created_at' => now()->subMonths(61),
            'updated_at' => now()->subMonths(61),
        ]);

        $hold = DataHold::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'reason' => 'litigation-hold',
            'created_by' => $this->owner->id,
            'until' => now()->addYear(),
        ]);

        $service = app(PurgeService::class);

        $held = $service->purgeBatch('medical_documents');

        $this->assertSame(0, $held->purged);
        $this->assertSame(1, $held->held);
        $this->assertDatabaseHas('medical_documents', ['id' => $old->id]);
        Storage::disk('local')->assertExists($old->storage_path);

        app(LegalHold::class)->release($hold->id, $this->owner->id);

        $purged = $service->purgeBatch('medical_documents');

        $this->assertSame(1, $purged->purged);
        $this->assertDatabaseMissing('medical_documents', ['id' => $old->id]);
        Storage::disk('local')->assertMissing($old->storage_path);

        // A document already soft-deleted by the API is purged too; the job
        // normally removed the object first, so a missing object is not an
        // error and the metadata row is still removed.
        $softDeleted = $this->makeDocument($this->profile, [
            'status' => MedicalDocument::STATUS_DELETED,
            'deleted_at' => now()->subMonths(61),
            'created_at' => now()->subMonths(61),
            'updated_at' => now()->subMonths(61),
        ]);

        Storage::disk('local')->delete($softDeleted->storage_path);

        $second = $service->purgeBatch('medical_documents');

        $this->assertSame(1, $second->purged);
        $this->assertDatabaseMissing('medical_documents', ['id' => $softDeleted->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPhotoTask(): array
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson($this->careTasksPath(), [
            'profile_id' => $this->profile->id,
            'tier' => 'clinical',
            'type' => 'DOCUMENT_FOLLOW_UP',
            'title' => 'Review uploaded document',
            'priority' => 'normal',
            'due_at' => now()->addHour()->toIso8601String(),
            'evidence_policy' => 'photo',
            'assignee_user_id' => $this->caregiver->id,
            'assignee_can_complete' => true,
        ])
            ->assertCreated()
            ->json('data');
    }

    private function careTasksPath(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/care-tasks";
    }

    private function careTaskPath(string $taskId): string
    {
        return $this->careTasksPath().'/'.$taskId;
    }
}
