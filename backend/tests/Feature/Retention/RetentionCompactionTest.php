<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Retention\Models\DataHold;
use App\Modules\Retention\Services\LegalHold;
use App\Modules\Retention\Services\PurgeService;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-C01..C06 — physical deletion of already soft-deleted rows (P1).
 *
 * The purge only hides data; these tests prove that the compaction pass
 * removes it, respects holds and the dry-run switch, keeps its audit trail and
 * can be run twice without deleting anything the second time.
 */
class RetentionCompactionTest extends TestCase
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

        $this->tenant = $this->createTenant('retention-compact-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Retention Compact Workspace');

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

    public function test_ret_c01_nothing_inside_the_grace_window_is_removed(): void
    {
        config(['retention.dry_run' => false]);

        $recent = $this->measurement(now()->subMonths(61), now()->subDays(5));

        $report = app(PurgeService::class)->compactBatch('health_measurements', 30);

        $this->assertSame(0, $report['compacted']);
        // "Eligible" starts at the grace boundary; a row deleted five days ago
        // is not eligible yet, so the note survives the run untouched.
        $this->assertSame(0, $report['evaluated']);
        $this->assertNull($report['skipped']);
        $this->assertFalse($report['dry_run']);

        // Still soft-deleted, never physically removed before its grace ends.
        $this->assertSoftDeleted('health_measurements', ['id' => $recent->id]);

        // A type with no `deleted_at` column is reported, not guessed at, and
        // writes no audit row because nothing happened.
        $auditBefore = DB::table('security_audit_logs')->count();

        $skipped = app(PurgeService::class)->compactBatch('task_events', 30);

        $this->assertSame('no_soft_deletes', $skipped['skipped']);
        $this->assertSame(0, $skipped['evaluated']);
        $this->assertSame($auditBefore, DB::table('security_audit_logs')->count());
    }

    public function test_ret_c02_past_the_grace_window_the_row_is_gone_and_audited(): void
    {
        config(['retention.dry_run' => false]);

        $expired = $this->measurement(now()->subMonths(61), now()->subDays(31));
        $kept = $this->measurement(now()->subMonths(61), now()->subDays(29));

        $auditBefore = DB::table('security_audit_logs')->count();

        $report = app(PurgeService::class)->compactBatch('health_measurements', 30);

        $this->assertSame(1, $report['compacted']);
        $this->assertSame(1, $report['evaluated']);
        $this->assertSame(0, $report['held']);

        // Physically gone, not merely soft-deleted.
        $this->assertDatabaseMissing('health_measurements', ['id' => $expired->id]);
        $this->assertSoftDeleted('health_measurements', ['id' => $kept->id]);

        $this->assertSame($auditBefore + 1, DB::table('security_audit_logs')->count());

        $audit = DB::table('security_audit_logs')
            ->where('action', 'retention.compact')
            ->orderByDesc('seq')
            ->first();

        $this->assertNotNull($audit, 'A compaction batch must write one security audit row.');
        $this->assertSame('allow', $audit->result);
        $this->assertSame($this->workspace->id, $audit->workspace_id);

        $metadata = json_decode($audit->metadata, true);

        $this->assertSame('health_measurements', $metadata['retention_type']);
        $this->assertSame(30, $metadata['grace_days']);
        $this->assertSame(1, $metadata['compacted']);
        $this->assertSame(1, $metadata['evaluated']);
        $this->assertFalse($metadata['dry_run']);

        // A grace period below one day is refused, at the service and at the CLI.
        try {
            app(PurgeService::class)->compactBatch('health_measurements', 0);
            $this->fail('A zero-day grace period must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at least 1 day', $e->getMessage());
        }

        $this->artisan('retention:compact', ['--older-than' => 0])->assertFailed();

        // Audit data is never a compaction target (DM-R05).
        foreach (['audit_events', 'security_audit_logs', 'audit_months'] as $forbidden) {
            try {
                app(PurgeService::class)->compactBatch($forbidden, 30);
                $this->fail("Compaction must reject [{$forbidden}] (DM-R05).");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        try {
            app(PurgeService::class)->compactBatch('not_a_retention_type', 30);
            $this->fail('An unknown retention type must be rejected.');
        } catch (InvalidArgumentException) {
            // Expected.
        }
    }

    public function test_ret_c03_an_active_legal_hold_blocks_compaction(): void
    {
        config(['retention.dry_run' => false]);

        $measurement = $this->measurement(now()->subMonths(61), now()->subDays(31));

        $hold = DataHold::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profileId,
            'reason' => 'litigation-hold',
            'created_by' => $this->user->id,
            'until' => now()->addYear(),
        ]);

        $report = app(PurgeService::class)->compactBatch('health_measurements', 30);

        $this->assertSame(0, $report['compacted']);
        $this->assertSame(1, $report['held']);
        $this->assertDatabaseHas('health_measurements', ['id' => $measurement->id]);

        // A workspace-wide hold skips the whole workspace.
        $hold->forceFill(['profile_id' => null])->save();

        $report = app(PurgeService::class)->compactBatch('health_measurements', 30);

        $this->assertSame(0, $report['compacted']);
        $this->assertSame(1, $report['held']);
        $this->assertSame('legal_hold', $report['skipped']);
        $this->assertDatabaseHas('health_measurements', ['id' => $measurement->id]);

        // Releasing the hold makes the row compactable again.
        $this->assertTrue(app(LegalHold::class)->release($hold->id, $this->user->id));

        $report = app(PurgeService::class)->compactBatch('health_measurements', 30);

        $this->assertSame(1, $report['compacted']);
        $this->assertNull($report['skipped']);
        $this->assertDatabaseMissing('health_measurements', ['id' => $measurement->id]);
    }

    public function test_ret_c04_a_second_run_removes_nothing_and_dry_run_removes_nothing(): void
    {
        config(['retention.dry_run' => false]);

        $measurement = $this->measurement(now()->subMonths(61), now()->subDays(31));

        $service = app(PurgeService::class);

        $this->assertSame(1, $service->compactBatch('health_measurements', 30)['compacted']);

        $second = $service->compactBatch('health_measurements', 30);

        $this->assertSame(0, $second['compacted']);
        $this->assertSame(0, $second['evaluated']);
        $this->assertDatabaseMissing('health_measurements', ['id' => $measurement->id]);

        // dry_run (the deployment default) is a hard stop even past the grace.
        $retained = $this->measurement(now()->subMonths(61), now()->subDays(31));
        config(['retention.dry_run' => true]);

        $dryRun = $service->compactBatch('health_measurements', 30);

        $this->assertTrue($dryRun['dry_run']);
        $this->assertSame(1, $dryRun['evaluated']);
        $this->assertSame(0, $dryRun['compacted']);
        $this->assertDatabaseHas('health_measurements', ['id' => $retained->id]);

        $this->artisan('retention:compact', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('health_measurements', ['id' => $retained->id]);
    }

    public function test_ret_c05_a_document_object_is_removed_before_its_metadata_row(): void
    {
        config(['retention.dry_run' => false]);
        Storage::fake('local');

        $path = 'documents/'.$this->tenant->id.'/'.Uuid7::generate();
        Storage::disk('local')->put($path, 'document-bytes');

        $document = $this->document(now()->subDays(31), $path);

        $report = app(PurgeService::class)->compactBatch('medical_documents', 30);

        $this->assertSame(1, $report['compacted']);
        $this->assertNull($report['skipped']);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('medical_documents', ['id' => $document->id]);
    }

    public function test_ret_c06_a_failed_object_delete_keeps_the_row_for_a_retry(): void
    {
        config(['retention.dry_run' => false]);

        $document = $this->document(now()->subDays(31), 'documents/'.$this->tenant->id.'/retry-me');

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->once()->andReturnTrue();
        $disk->shouldReceive('delete')->once()->andReturnFalse();

        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $report = app(PurgeService::class)->compactBatch('medical_documents', 30);

        $this->assertSame(0, $report['compacted']);
        $this->assertSame('object_delete_failed', $report['skipped']);
        $this->assertDatabaseHas('medical_documents', ['id' => $document->id]);
    }

    private function measurement(CarbonInterface $recordedAt, ?CarbonInterface $deletedAt = null): HealthMeasurement
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
            'deleted_at' => $deletedAt,
        ]);
    }

    private function document(CarbonInterface $deletedAt, string $path): MedicalDocument
    {
        return MedicalDocument::query()->forceCreate([
            'id' => Uuid7::generate(),
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
            'deleted_at' => $deletedAt,
        ]);
    }
}
