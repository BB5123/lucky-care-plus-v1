<?php

namespace Tests\Feature\AiGateway;

use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Documents\DocumentTestCase;

/**
 * G4 gate — EXPORT-01 ... EXPORT-04 (ADR-018, `.ai/10-TESTING.md` G4).
 *
 * The export surfaces that exist today are the medical-document signed link
 * and the Doctor Brief share. Both are gated by the *export* permission, not
 * by `.read` (F-06), both are time-bounded, and each writes exactly one
 * PHI-free security-audit decision. The `data_export.*` permissions are in the
 * catalogue but have no route, so the API cannot leak data through them.
 */
class ExportGateTest extends DocumentTestCase
{
    public function test_export_01_a_document_download_url_requires_export_not_read(): void
    {
        Sanctum::actingAs($this->owner);

        $document = $this->uploadPdf();
        $this->consent('data_export');

        // `medical_document.read` is not `medical_document.export` (F-06).
        $this->grant($this->caregiver, ['documents'], ['read']);

        Sanctum::actingAs($this->caregiver);

        $this->postJson($this->documentUrlPath($document['id']))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->grant($this->caregiver, ['documents'], ['export']);

        $response = $this->postJson($this->documentUrlPath($document['id']))->assertOk();

        $this->assertStringContainsString(
            '/api/v1/documents/'.$document['id'].'/download',
            (string) $response->json('data.url'),
        );

        // The route itself declares the export permission; the test above is
        // the behavioural proof, this is the declaration guard.
        $route = app('router')->getRoutes()->match(
            Request::create($this->documentUrlPath($document['id']), 'POST'),
        );

        $this->assertContains(
            'authorize.permission:medical_document.export',
            $route->gatherMiddleware(),
        );
    }

    public function test_export_02_a_doctor_brief_share_requires_export_and_expires_after_30_minutes(): void
    {
        Sanctum::actingAs($this->owner);

        $this->consent('data_export');

        $reportId = $this->postJson($this->reportsPath())->assertCreated()->json('data.id');

        // A read grant is not an export grant.
        $this->grant($this->caregiver, ['profile.basic'], ['read']);

        Sanctum::actingAs($this->caregiver);

        $this->postJson($this->sharePath($reportId))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->grant($this->caregiver, ['profile.basic'], ['export']);

        $share = $this->postJson($this->sharePath($reportId))->assertCreated()->json('data');

        $route = app('router')->getRoutes()->match(
            Request::create($this->sharePath($reportId), 'POST'),
        );

        $this->assertContains(
            'authorize.permission:health_report.export',
            $route->gatherMiddleware(),
        );

        $this->getJson($share['url'])->assertOk();

        // The capability is capped at 30 minutes (DB-003); one minute past it
        // the same link is indistinguishable from an unknown token.
        $this->travel(31)->minutes();

        $this->getJson($share['url'])
            ->assertNotFound()
            ->assertJsonPath('code', 'out_of_scope');
    }

    public function test_export_03_data_export_permissions_are_catalogued_but_have_no_route(): void
    {
        $catalog = (array) config('authorization.permissions');

        $this->assertContains('data_export.request', $catalog);
        $this->assertContains('data_export.download', $catalog);

        // No API surface exists for them yet: a catalogued ID without a route is
        // a capability that cannot be invoked, which is the safe state.
        $exportRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->filter(static fn (string $uri): bool => str_contains($uri, 'data-export')
                || str_contains($uri, 'data_export'))
            ->values()
            ->all();

        $this->assertSame([], $exportRoutes);

        $this->useWorkspace();
        $auditBefore = DB::table('security_audit_logs')->count();

        Sanctum::actingAs($this->owner);

        $request = $this->postJson($this->dataExportPath(), ['format' => 'json']);
        $request->assertNotFound();

        $body = (array) $request->json();

        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayNotHasKey('download_url', $body);

        $this->getJson($this->dataExportPath().'/download')->assertNotFound();

        // No authorization decision and therefore no data path was reached.
        $this->useWorkspace();
        $this->assertSame($auditBefore, DB::table('security_audit_logs')->count());
    }

    public function test_export_04_every_export_path_writes_exactly_one_phi_free_audit_row(): void
    {
        Sanctum::actingAs($this->owner);

        $this->makePhiCondition();
        $this->makePhiMeasurement();

        $document = $this->uploadPdf();
        $this->consent('data_export');
        $reportId = $this->postJson($this->reportsPath())->assertCreated()->json('data.id');

        $this->useWorkspace();
        $before = DB::table('security_audit_logs')->count();

        // 1. Issue a document download URL.
        $url = $this->postJson($this->documentUrlPath($document['id']))
            ->assertOk()
            ->json('data.url');

        $this->assertOneExportAuditRow($before + 1, 'medical_document.export', 'health_profile', $this->profile->id);

        // 2. Deliver the signed document.
        $this->get($url)->assertOk();

        $this->assertOneExportAuditRow($before + 2, 'medical_document.export', 'health_profile', $this->profile->id);

        // 3. Mint a Doctor Brief share.
        $share = $this->postJson($this->sharePath($reportId))->assertCreated()->json('data');

        $this->assertOneExportAuditRow($before + 3, 'health_report.export', 'health_profile', $this->profile->id);

        // 4. Read the shared brief through its public capability.
        $this->getJson($share['url'])->assertOk();

        $this->assertOneExportAuditRow($before + 4, 'report.share.accessed', 'health_report', $reportId);

        // None of the four rows may contain PHI. The fixtures deliberately put
        // a marker in the condition name, the person name and the uploaded
        // filename, so a leak would be visible here.
        $this->useWorkspace();

        $rows = DB::table('security_audit_logs')
            ->orderByDesc('seq')
            ->limit(4)
            ->get(['action', 'target_type', 'target_id', 'metadata']);

        $this->assertCount(4, $rows);

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
            'scan.pdf',
            'Owner Person',
            '081-234-5678',
            '1-2345-67890-12-3',
            '081-999-9999',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $haystack, "An export audit row leaked {$forbidden}");
        }
    }

    private function dataExportPath(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/profiles/{$this->profile->id}/data-exports";
    }

    private function reportsPath(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/profiles/{$this->profile->id}/health-reports";
    }

    private function sharePath(string $reportId): string
    {
        return $this->reportsPath()."/{$reportId}/share";
    }

    private function assertOneExportAuditRow(
        int $expectedCount,
        string $action,
        string $targetType,
        string $targetId,
    ): void {
        $this->useWorkspace();

        $this->assertSame($expectedCount, DB::table('security_audit_logs')->count());

        $row = DB::table('security_audit_logs')->orderByDesc('seq')->first();

        $this->assertNotNull($row);
        $this->assertSame($action, $row->action);
        $this->assertSame('allow', $row->result);
        $this->assertSame($targetType, $row->target_type);
        $this->assertSame($targetId, $row->target_id);
    }

    private function makePhiCondition(): void
    {
        $this->useWorkspace();

        HealthCondition::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'code' => null,
            'name' => 'PHI-CONDITION-MARKER',
            'diagnosed_at' => now()->subYears(2),
            'status' => 'active',
            'notes' => null,
            'version' => 1,
        ]);
    }

    private function makePhiMeasurement(): void
    {
        $this->useWorkspace();

        HealthMeasurement::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'type' => 'heart_rate',
            'value' => 199,
            'secondary_value' => null,
            'unit' => 'bpm',
            'context' => null,
            'note' => null,
            'recorded_at' => now()->subDay(),
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'version' => 1,
        ]);
    }
}
