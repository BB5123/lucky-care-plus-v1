<?php

namespace Tests\Feature\Export;

use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\HealthRecord\Jobs\BuildDataExport;
use App\Modules\HealthRecord\Models\DataExport;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Documents\DocumentTestCase;

/**
 * PDPA data-export acceptance suite (EXP-T01 ... EXP-T10).
 *
 * The fixtures reuse the document suite so the export is exercised against a
 * realistic profile with a real private document object. Every test starts
 * from a workspace/profile and asks the real HTTP surface; the queued job is
 * run explicitly because `data_export.requested` is dispatched after commit.
 */
final class DataExportTest extends DocumentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'exports.disk' => 'local',
            'exports.retention_days' => 7,
            'exports.rate_limit_hours' => 24,
            'exports.link_ttl_minutes' => 30,
            'exports.max_link_ttl_minutes' => 30,
            'exports.format_version' => 1,
        ]);
    }

    public function test_exp_t01_without_data_export_consent_the_request_is_forbidden_and_creates_no_row(): void
    {
        Sanctum::actingAs($this->owner);
        Queue::fake();

        $this->postJson($this->exportsPath(), ['format' => 'json'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_consent');

        // The status and link endpoints are consent-gated too, even when the
        // id is unknown: no existence probe is possible without consent.
        $unknownId = Uuid7::generate();

        $this->getJson($this->exportsPath(null, null, $unknownId))
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_consent');

        $this->postJson($this->linkPath($unknownId))
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_consent');

        $this->useWorkspace();

        $this->assertSame(0, DB::table('data_exports')->count());
        Queue::assertNotPushed(BuildDataExport::class);
    }

    public function test_exp_t02_a_request_returns_202_queues_the_row_and_dispatches_the_job(): void
    {
        $this->consent('data_export');
        Sanctum::actingAs($this->owner);
        Queue::fake();

        $response = $this->postJson($this->exportsPath(), ['format' => 'json'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', DataExport::STATUS_QUEUED)
            ->assertJsonPath('data.format', DataExport::FORMAT_JSON);

        $exportId = (string) $response->json('data.id');

        $this->assertNotSame('', $exportId);
        $this->assertArrayNotHasKey('url', (array) $response->json('data'));
        $this->assertArrayNotHasKey('object_key', (array) $response->json('data'));

        $this->useWorkspace();

        $export = DataExport::query()->findOrFail($exportId);

        $this->assertSame(DataExport::STATUS_QUEUED, $export->status);
        $this->assertSame($this->owner->id, $export->requested_by);
        $this->assertNull($export->object_key);
        $this->assertNull($export->byte_size);
        $this->assertNull($export->expires_at);
        $this->assertNotNull($export->requested_at);

        Queue::assertPushed(
            BuildDataExport::class,
            fn (BuildDataExport $job): bool => $job->exportId === $exportId
                && $job->tenantId === $this->tenant->id
                && $job->workspaceId === $this->workspace->id,
        );
    }

    public function test_exp_t03_the_built_json_contains_the_record_and_document_metadata_but_no_document_bytes(): void
    {
        $this->consent('data_export');

        $condition = $this->makeCondition('PHI-CONDITION-MARKER');
        $measurement = $this->makeMeasurement('MEASUREMENT-NOTE-MARKER');
        $task = $this->makeCareTask('Check blood pressure');
        $event = $this->makeTaskEvent($task);
        $document = $this->makeDocument(null, ['original_name' => 'report.pdf']);

        // Replace the stored object with a unique marker. The export must carry
        // metadata only, so this marker must never appear in the JSON.
        Storage::disk('local')->put($document->storage_path, 'DOCUMENT-BYTES-MARKER');

        Sanctum::actingAs($this->owner);
        Queue::fake();

        $exportId = (string) $this->postJson($this->exportsPath(), ['format' => 'json'])
            ->assertStatus(202)
            ->json('data.id');

        $this->runBuild($exportId);

        $this->useWorkspace();

        $export = DataExport::query()->findOrFail($exportId);

        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $this->assertNotNull($export->object_key);
        $this->assertGreaterThan(0, (int) $export->byte_size);
        $this->assertNotNull($export->completed_at);
        $this->assertNotNull($export->expires_at);
        $this->assertTrue($export->expires_at->greaterThan(now()->addDays(6)));
        $this->assertTrue($export->expires_at->lessThan(now()->addDays(8)));

        $json = Storage::disk('local')->get((string) $export->object_key);

        $this->assertIsString($json);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($exportId, $payload['meta']['export_id']);
        $this->assertSame($this->profile->id, $payload['meta']['profile_id']);
        $this->assertSame(DataExport::FORMAT_JSON, $payload['meta']['format']);
        $this->assertSame(1, $payload['meta']['format_version']);
        $this->assertNotSame('', (string) $payload['meta']['notice']);

        $this->assertCount(1, $payload['conditions']);
        $this->assertSame($condition->id, $payload['conditions'][0]['id']);
        $this->assertSame('PHI-CONDITION-MARKER', $payload['conditions'][0]['name']);

        $this->assertCount(1, $payload['measurements']);
        $this->assertSame($measurement->id, $payload['measurements'][0]['id']);
        $this->assertSame(199.0, $payload['measurements'][0]['value']);
        $this->assertSame('MEASUREMENT-NOTE-MARKER', $payload['measurements'][0]['note']);

        $this->assertCount(1, $payload['care_tasks']);
        $this->assertSame($task->id, $payload['care_tasks'][0]['id']);
        $this->assertSame('Check blood pressure', $payload['care_tasks'][0]['title']);

        $this->assertCount(1, $payload['task_events']);
        $this->assertSame($event->id, $payload['task_events'][0]['id']);
        $this->assertSame('TASK-EVENT-REASON-MARKER', $payload['task_events'][0]['reason']);

        $this->assertCount(1, $payload['documents']);
        $this->assertSame($document->id, $payload['documents'][0]['id']);
        $this->assertSame('lab_result', $payload['documents'][0]['type']);
        $this->assertSame('report.pdf', $payload['documents'][0]['original_name']);
        $this->assertArrayHasKey('created_at', $payload['documents'][0]);
        $this->assertArrayHasKey('size_bytes', $payload['documents'][0]);

        // A data export is a copy of the record, not a bulk download.
        $this->assertArrayNotHasKey('storage_path', $payload['documents'][0]);
        $this->assertArrayNotHasKey('sha256', $payload['documents'][0]);
        $this->assertArrayNotHasKey('bytes', $payload['documents'][0]);
        $this->assertStringNotContainsString('DOCUMENT-BYTES-MARKER', $json);
        $this->assertStringNotContainsString('%PDF', $json);
        $this->assertStringNotContainsString('storage_path', $json);
    }

    public function test_exp_t04_a_second_request_inside_24_hours_is_rate_limited_with_the_remaining_time(): void
    {
        $this->consent('data_export');
        Sanctum::actingAs($this->owner);
        Queue::fake();

        $this->postJson($this->exportsPath(), ['format' => 'json'])->assertStatus(202);

        $second = $this->postJson($this->exportsPath(), ['format' => 'json'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'export_rate_limited');

        $this->assertGreaterThan(0, (int) $second->json('retry_after_seconds'));
        $this->assertLessThanOrEqual(86_400, (int) $second->json('retry_after_seconds'));
        $this->assertGreaterThan(0, (int) $second->headers->get('Retry-After'));

        $this->useWorkspace();
        $this->assertSame(1, DB::table('data_exports')->count());

        // One minute past the window is a new request.
        $this->travel(24)->hours();
        $this->travel(1)->minutes();

        $this->postJson($this->exportsPath(), ['format' => 'json'])->assertStatus(202);

        $this->useWorkspace();
        $this->assertSame(2, DB::table('data_exports')->count());
        Queue::assertPushed(BuildDataExport::class, 2);
    }

    public function test_exp_t05_the_link_requires_download_permission_and_expires_within_30_minutes(): void
    {
        $this->consent('data_export');

        Sanctum::actingAs($this->owner);
        Queue::fake();

        $exportId = (string) $this->postJson($this->exportsPath(), ['format' => 'json'])
            ->assertStatus(202)
            ->json('data.id');

        $this->runBuild($exportId);

        Sanctum::actingAs($this->caregiver);

        // A read grant is not an export grant (F-06).
        $this->grant($this->caregiver, ['profile.basic'], ['read']);

        $this->postJson($this->linkPath($exportId))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->grant($this->caregiver, ['profile.basic'], ['export']);

        $link = $this->postJson($this->linkPath($exportId))
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at']]);

        $this->assertNotSame('', (string) $link->json('data.url'));
        $this->assertArrayNotHasKey('object_key', (array) $link->json('data'));

        $expiresAt = Carbon::parse((string) $link->json('data.expires_at'));

        $this->assertTrue($expiresAt->greaterThan(now()));
        $this->assertTrue($expiresAt->lessThanOrEqualTo(now()->addMinutes(30)));

        $route = app('router')->getRoutes()->match(
            Request::create($this->linkPath($exportId), 'POST'),
        );

        $this->assertContains(
            'authorize.permission:data_export.download',
            $route->gatherMiddleware(),
        );
    }

    public function test_exp_t06_an_expired_export_returns_410_and_is_marked_expired_once(): void
    {
        $this->consent('data_export');

        Sanctum::actingAs($this->owner);
        Queue::fake();

        $exportId = (string) $this->postJson($this->exportsPath(), ['format' => 'json'])
            ->assertStatus(202)
            ->json('data.id');

        $this->runBuild($exportId);

        $this->useWorkspace();
        $export = DataExport::query()->findOrFail($exportId);
        $export->forceFill(['expires_at' => now()->subMinute()])->save();

        Sanctum::actingAs($this->owner);

        $this->postJson($this->linkPath($exportId))
            ->assertStatus(410)
            ->assertJsonPath('code', 'export_expired');

        $this->useWorkspace();

        $this->assertSame(
            DataExport::STATUS_EXPIRED,
            DataExport::query()->findOrFail($exportId)->status,
        );
        $this->assertSame(
            1,
            DB::table('security_audit_logs')->where('action', 'data_export.expired')->count(),
        );

        // The status endpoint still reports the terminal state, without a key
        // or a URL.
        $status = $this->getJson($this->exportsPath(null, null, $exportId))
            ->assertOk()
            ->assertJsonPath('data.status', DataExport::STATUS_EXPIRED);

        $this->assertArrayNotHasKey('object_key', (array) $status->json('data'));
        $this->assertArrayNotHasKey('url', (array) $status->json('data'));
    }

    public function test_exp_t07_another_workspaces_export_id_is_404_not_403(): void
    {
        $this->consent('data_export');

        // Build the other workspace's row as a fixture: this test is about
        // route scoping, not about the other workspace's consent lifecycle.
        $this->useWorkspace($this->otherWorkspace);

        $otherExport = DataExport::query()->create([
            'tenant_id' => $this->otherWorkspace->tenant_id,
            'workspace_id' => $this->otherWorkspace->id,
            'profile_id' => $this->otherWorkspaceProfile->id,
            'requested_by' => $this->owner->id,
            'status' => DataExport::STATUS_QUEUED,
            'format' => DataExport::FORMAT_JSON,
            'requested_at' => now(),
        ]);

        $this->useWorkspace();

        Sanctum::actingAs($this->owner);

        // The same profile id under the wrong workspace is out of scope, not a
        // permission error on a row that exists elsewhere.
        $this->postJson(
            $this->exportsPath($this->otherWorkspaceProfile, $this->workspace),
            ['format' => 'json'],
        )
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');

        $this->getJson($this->exportsPath(null, null, $otherExport->id))
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');

        $this->postJson($this->linkPath($otherExport->id))
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    public function test_exp_t08_every_state_change_writes_one_phi_free_audit_row(): void
    {
        $this->consent('data_export');

        $this->makeCondition('PHI-CONDITION-MARKER');
        $this->makeMeasurement('PHI-MEASUREMENT-NOTE-MARKER');
        $this->makeCareTask('PHI-TASK-TITLE-MARKER');
        $this->makeDocument(null, ['original_name' => 'PHI-FILENAME.pdf']);

        Sanctum::actingAs($this->owner);
        Queue::fake();

        $exportId = (string) $this->postJson($this->exportsPath(), ['format' => 'json'])
            ->assertStatus(202)
            ->json('data.id');

        $this->runBuild($exportId);

        $this->useWorkspace();

        $rows = DB::table('security_audit_logs')
            ->whereIn('action', ['data_export.requested', 'data_export.building', 'data_export.ready'])
            ->orderBy('seq')
            ->get(['action', 'target_type', 'target_id', 'metadata']);

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['data_export.requested', 'data_export.building', 'data_export.ready'],
            $rows->pluck('action')->all(),
        );

        $export = DataExport::query()->findOrFail($exportId);

        foreach ($rows as $row) {
            $this->assertSame('data_export', $row->target_type);
            $this->assertSame($exportId, $row->target_id);
        }

        $haystack = $rows
            ->map(static fn (object $row): string => implode(' ', [
                (string) $row->action,
                (string) $row->target_type,
                (string) $row->target_id,
                (string) $row->metadata,
            ]))
            ->implode(' ');

        foreach ([
            'PHI-CONDITION-MARKER',
            'PHI-MEASUREMENT-NOTE-MARKER',
            'PHI-TASK-TITLE-MARKER',
            'PHI-FILENAME.pdf',
            'exports/',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $haystack);
        }

        $this->assertStringNotContainsString((string) $export->object_key, $haystack);
    }

    public function test_exp_t09_a_failed_build_leaves_no_object_and_marks_the_row_failed(): void
    {
        $consent = $this->consent('data_export');

        Sanctum::actingAs($this->owner);
        Queue::fake();

        $exportId = (string) $this->postJson($this->exportsPath(), ['format' => 'json'])
            ->assertStatus(202)
            ->json('data.id');

        $objectKey = 'exports/'.$this->tenant->id.'/'.$exportId.'.json';

        // Simulate a partial object from an interrupted previous attempt.
        Storage::disk('local')->put($objectKey, '{"partial":true}');

        // The queued job re-authorizes; withdrawing consent must fail closed.
        $this->useWorkspace();
        $consent->withdrawn_at = now();
        $consent->save();

        $this->runBuild($exportId);

        $this->useWorkspace();

        $export = DataExport::query()->findOrFail($exportId);

        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertSame('access_revoked', $export->error_code);
        $this->assertNull($export->object_key);
        $this->assertNull($export->byte_size);
        $this->assertNull($export->expires_at);

        Storage::disk('local')->assertMissing($objectKey);

        $this->assertSame(
            1,
            DB::table('security_audit_logs')->where('action', 'data_export.failed')->count(),
        );
        $this->assertSame(
            1,
            DB::table('security_audit_logs')->where('action', 'data_export.building')->count(),
        );
        $this->assertSame(
            1,
            DB::table('security_audit_logs')
                ->where('action', 'data_export.request')
                ->where('result', 'deny')
                ->count(),
        );
    }

    public function test_exp_t10_the_three_data_export_routes_exist_with_their_permissions(): void
    {
        $catalog = (array) config('authorization.permissions');

        $this->assertContains('data_export.request', $catalog);
        $this->assertContains('data_export.download', $catalog);

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains($route->uri(), '/profiles/{profile}/exports'))
            ->keyBy(static fn ($route): string => $route->uri());

        $base = 'api/v1/workspaces/{workspace}/profiles/{profile}/exports';

        $this->assertCount(3, $routes);
        $this->assertTrue($routes->has($base));
        $this->assertTrue($routes->has($base.'/{export}'));
        $this->assertTrue($routes->has($base.'/{export}/link'));

        $this->assertContains(
            'authorize.permission:data_export.request',
            $routes[$base]->gatherMiddleware(),
        );
        $this->assertContains(
            'authorize.permission:data_export.request',
            $routes[$base.'/{export}']->gatherMiddleware(),
        );
        $this->assertContains(
            'authorize.permission:data_export.download',
            $routes[$base.'/{export}/link']->gatherMiddleware(),
        );
    }

    private function exportsPath(
        ?HealthProfile $profile = null,
        ?Workspace $workspace = null,
        ?string $exportId = null,
    ): string {
        $profile ??= $this->profile;
        $workspace ??= $this->workspace;

        $path = "/api/v1/workspaces/{$workspace->id}/profiles/{$profile->id}/exports";

        return $exportId === null ? $path : "{$path}/{$exportId}";
    }

    private function linkPath(string $exportId): string
    {
        return $this->exportsPath(null, null, $exportId).'/link';
    }

    private function runBuild(string $exportId, ?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        $job = new BuildDataExport($exportId, $workspace->tenant_id, $workspace->id);

        // Resolve the job's handle() dependencies the same way the queue worker
        // does, then restore the test context for assertions.
        app()->call([$job, 'handle']);

        $this->useWorkspace($workspace);
    }

    private function makeCondition(string $name): HealthCondition
    {
        $this->useWorkspace();

        return HealthCondition::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'code' => null,
            'name' => $name,
            'diagnosed_at' => now()->subYears(2),
            'status' => 'active',
            'notes' => null,
            'version' => 1,
        ]);
    }

    private function makeMeasurement(string $note): HealthMeasurement
    {
        $this->useWorkspace();

        return HealthMeasurement::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'type' => 'heart_rate',
            'value' => 199,
            'secondary_value' => null,
            'unit' => 'bpm',
            'context' => null,
            'note' => $note,
            'recorded_at' => now()->subDay(),
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'version' => 1,
        ]);
    }

    private function makeCareTask(string $title): CareTask
    {
        $this->useWorkspace();

        return CareTask::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'created_by' => $this->owner->id,
            'tier' => CareTask::TIER_ROUTINE,
            'type' => 'CUSTOM',
            'title' => $title,
            'description' => 'TASK-DESCRIPTION-MARKER',
            'priority' => 'normal',
            'scheduled_for' => now(),
            'due_at' => now()->addDay(),
            'timezone' => 'Asia/Bangkok',
            'status' => CareTask::STATUS_OPEN,
            'outcome' => null,
            'verification' => CareTask::VERIFICATION_NOT_REQUIRED,
            'verification_required' => false,
            'evidence_policy' => CareTask::EVIDENCE_NONE,
            'escalation_level' => 0,
            'blocked_reason' => null,
            'review_at' => null,
            'completed_at' => null,
            'version' => 1,
            'source' => 'manual',
        ]);
    }

    private function makeTaskEvent(CareTask $task): TaskEvent
    {
        $this->useWorkspace();

        return TaskEvent::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'task_id' => $task->id,
            'actor_id' => $this->owner->id,
            'actor_type' => 'user',
            'from_status' => null,
            'to_status' => CareTask::STATUS_OPEN,
            'event' => 'created',
            'reason' => 'TASK-EVENT-REASON-MARKER',
            'metadata' => [],
            'occurred_at' => now(),
        ]);
    }
}
