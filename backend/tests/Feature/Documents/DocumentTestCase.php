<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * Fixtures for the medical-document acceptance suite (DOC-T01..T12).
 *
 * Two workspaces under one tenant prove route/profile isolation. The owner is
 * the person behind the main profile; the dependent profile is managed by the
 * owner but deliberately *not* owned, so ownership and grant checks stay
 * distinguishable (the Authorizer only treats `persons.user_id` as ownership).
 */
abstract class DocumentTestCase extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Workspace $workspace;

    protected Workspace $otherWorkspace;

    protected User $owner;

    protected User $caregiver;

    protected User $viewer;

    protected HealthProfile $profile;

    protected HealthProfile $dependentProfile;

    protected HealthProfile $otherWorkspaceProfile;

    protected Person $ownerPerson;

    protected Person $dependentPerson;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'documents.disk' => 'local',
            'documents.scan_required' => false,
            'documents.max_bytes' => 10 * 1024 * 1024,
            'documents.url_ttl_minutes' => 5,
            'documents.url_max_ttl_minutes' => 15,
        ]);

        Storage::fake('local');

        $this->tenant = $this->createTenant('doc-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'DOC Workspace');
        $this->otherWorkspace = $this->createWorkspace($this->tenant, 'DOC Other Workspace');

        $this->owner = User::factory()->create();
        $this->caregiver = User::factory()->create();
        $this->viewer = User::factory()->create();

        $this->addMember($this->workspace, $this->owner, 'profile_owner');
        $this->addMember($this->workspace, $this->caregiver, 'caregiver');
        $this->addMember($this->workspace, $this->viewer, 'viewer');
        $this->addMember($this->otherWorkspace, $this->owner, 'profile_owner');

        $this->useWorkspace($this->workspace);

        $this->ownerPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'display_name' => 'Owner Person',
        ]);

        $this->profile = HealthProfile::forceCreate([
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
            'display_name' => 'Dependent Person',
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

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    protected function useWorkspace(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($this->tenant->id, $workspace->id);
    }

    protected function documentsPath(
        ?HealthProfile $profile = null,
        ?Workspace $workspace = null,
        ?string $documentId = null,
    ): string {
        $profile ??= $this->profile;
        $workspace ??= $this->workspace;

        $path = "/api/v1/workspaces/{$workspace->id}/profiles/{$profile->id}/documents";

        return $documentId === null ? $path : "{$path}/{$documentId}";
    }

    protected function documentUrlPath(string $documentId): string
    {
        return $this->documentsPath(null, null, $documentId).'/url';
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $actions
     */
    protected function grant(
        User $grantee,
        array $scopes,
        array $actions,
        ?HealthProfile $profile = null,
    ): AccessGrant {
        $profile ??= $this->profile;
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

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

    protected function consent(
        string $purpose,
        ?HealthProfile $profile = null,
    ): ConsentRecord {
        $profile ??= $this->profile;
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return ConsentRecord::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'consent_type' => $purpose,
            'purpose' => $purpose,
            'scope' => ['documents'],
            'policy_version' => 'v1.0.0',
            'consenting_subject_id' => $this->ownerPerson->id,
            'legal_basis_type' => 'consent',
            'granted_at' => now(),
            'withdrawn_at' => null,
            'evidence_reference' => 'test',
            'superseded_by_id' => null,
        ]);
    }

    /**
     * Upload through the real endpoint, as the owner.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function uploadPdf(array $overrides = [], ?HealthProfile $profile = null): array
    {
        $profile ??= $this->profile;

        $response = $this->post(
            $this->documentsPath($profile),
            array_merge($this->uploadPayload(), $overrides),
            ['Accept' => 'application/json'],
        );

        $response->assertCreated();

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        return $data;
    }

    /**
     * Insert a document row plus its object without going through the API.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeDocument(
        ?HealthProfile $profile = null,
        array $attributes = [],
    ): MedicalDocument {
        $profile ??= $this->profile;
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        $id = Uuid7::generate();
        $path = 'documents/'.$profile->tenant_id.'/'.$id;
        $bytes = $this->pdfBytes();

        Storage::disk('local')->put($path, $bytes);

        return MedicalDocument::query()->forceCreate(array_merge([
            'id' => $id,
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'uploaded_by' => $this->owner->id,
            'kind' => 'lab_result',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'report.pdf',
            'captured_at' => null,
            'scan_status' => MedicalDocument::SCAN_SKIPPED,
            'status' => MedicalDocument::STATUS_AVAILABLE,
            'version' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function uploadPayload(array $overrides = []): array
    {
        return array_merge([
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', $this->pdfBytes()),
            'kind' => 'lab_result',
        ], $overrides);
    }

    protected function pdfBytes(): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
            ."trailer\n<< /Root 1 0 R >>\n"
            ."%%EOF\n";
    }

    protected function pngBytes(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        return is_string($png) ? $png : '';
    }

    protected function phpBytes(): string
    {
        return "<?php echo 'not-an-image'; ?>";
    }
}
