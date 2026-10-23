<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\HealthRecord\Application\HealthReportService;
use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\HealthReport;
use App\Modules\HealthRecord\Models\MedicationPlan;
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
 * P2.3 acceptance suite — BRF-T01 ... BRF-T12.
 *
 * The HTTP surface is exercised end-to-end because the route middleware
 * (Authorizer, workspace context) is part of the contract. Fixtures are written
 * directly inside the test's outer transaction with an explicit server-side
 * tenant/workspace context, as every RLS-protected table requires.
 */
class DoctorBriefTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    private User $owner;

    private User $caregiver;

    private User $viewer;

    private User $otherOwner;

    private HealthProfile $profile;

    private HealthProfile $otherProfile;

    private Person $ownerPerson;

    private Person $otherPerson;

    private HealthCondition $condition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('brf-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'BRF Workspace');
        $this->otherWorkspace = $this->createWorkspace($this->tenant, 'BRF Other Workspace');

        $this->owner = User::factory()->create(['name' => 'Owner User']);
        $this->caregiver = User::factory()->create(['name' => 'Caregiver User']);
        $this->viewer = User::factory()->create(['name' => 'Viewer User']);
        $this->otherOwner = User::factory()->create(['name' => 'Other Owner']);

        $this->addMember($this->workspace, $this->owner, 'profile_owner');
        $this->addMember($this->workspace, $this->caregiver, 'caregiver');
        $this->addMember($this->workspace, $this->viewer, 'viewer');
        $this->addMember($this->otherWorkspace, $this->otherOwner, 'profile_owner');

        $this->useWorkspace($this->workspace);

        $this->ownerPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'display_name' => 'Somchai Patient',
            'birth_date' => '1985-04-12',
        ]);

        $this->profile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $this->ownerPerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->otherWorkspace);

        $this->otherPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'user_id' => $this->otherOwner->id,
            'display_name' => 'Other Patient',
            'birth_date' => '1970-01-01',
        ]);

        $this->otherProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'person_id' => $this->otherPerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->workspace);

        $this->condition = $this->makeCondition();
        $this->makeCondition([
            'name' => 'Old Fracture',
            'diagnosed_at' => now()->subYears(20),
            'status' => 'resolved',
        ]);

        $this->makeAllergy();
        $this->makeMedicationPlan();
        $this->makeMedicationPlan([
            'name' => 'Amoxicillin',
            'status' => MedicationPlan::STATUS_DISCONTINUED,
            'discontinued_at' => now()->subMonth(),
            'discontinued_reason' => 'course finished',
        ]);

        $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 72,
            'unit' => 'bpm',
            'recorded_at' => now()->subDays(2),
        ]);
        $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 80,
            'unit' => 'bpm',
            'recorded_at' => now()->subDay(),
        ]);
        $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 120,
            'secondary_value' => 80,
            'unit' => 'mmHg',
            'recorded_at' => now()->subDays(3),
        ]);
        // Outside the default 30-day window: must not appear in the trend.
        $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 60,
            'unit' => 'bpm',
            'recorded_at' => now()->subDays(45),
        ]);

        $this->makeAppointment([
            'status' => Appointment::STATUS_ATTENDED,
            'scheduled_at' => now()->subDays(10),
        ]);
        $this->makeAppointment([
            'status' => Appointment::STATUS_PLANNED,
            'title' => 'Upcoming follow-up',
            'scheduled_at' => now()->addDays(3),
        ]);
        $this->makeAppointment([
            'status' => Appointment::STATUS_ATTENDED,
            'title' => 'Very old visit',
            'scheduled_at' => now()->subDays(120),
        ]);
        $this->makeAppointment([
            'status' => Appointment::STATUS_CANCELLED,
            'title' => 'Cancelled visit',
            'scheduled_at' => now()->addDays(5),
        ]);

        $this->makeCareTask(['status' => CareTask::STATUS_OPEN, 'title' => 'TASK-TITLE-MARKER']);
        $this->makeCareTask(['status' => CareTask::STATUS_ASSIGNED, 'title' => 'Another task']);
        $this->makeCareTask(['status' => CareTask::STATUS_COMPLETED, 'title' => 'Completed task']);

        // health_report.export is consent-gated (data_export purpose). The
        // owner has granted it; tests that share explicitly rely on this row.
        $this->makeExportConsent($this->profile, $this->ownerPerson->id);

        // The foreign-workspace fixture in BRF-T10 also mints a share, which is
        // consent-gated in its own workspace.
        $this->makeExportConsent($this->otherProfile, $this->otherPerson->id);

        $this->useWorkspace();
    }

    public function test_brf_t01_a_full_authority_owner_brief_contains_the_six_data_sections(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->reportsPath())
            ->assertCreated()
            ->assertJsonPath('data.kind', HealthReport::KIND_DOCTOR_BRIEF)
            ->assertJsonPath('data.status', HealthReport::STATUS_ACTIVE)
            ->assertJsonPath('data.generated_by', $this->owner->id);

        $data = $response->json('data');
        $payload = $data['payload'];

        $this->assertSame(
            ['conditions', 'allergies', 'medications', 'vitals', 'appointments', 'care_tasks'],
            $payload['sections'],
        );
        $this->assertSame('Somchai Patient', $payload['profile']['name']);
        $this->assertSame(1985, $payload['profile']['birth_year']);
        $this->assertSame('Type 2 Diabetes', $payload['conditions'][0]['name']);
        $this->assertSame(now()->subYears(8)->year, $payload['conditions'][0]['diagnosed_year']);
        $this->assertSame('Penicillin', $payload['allergies'][0]['substance']);
        $this->assertSame('severe', $payload['allergies'][0]['severity']);
        $this->assertSame('Metformin', $payload['medications'][0]['name']);
        $this->assertSame(['08:00', '20:00'], $payload['medications'][0]['schedule']);

        $vitals = collect($payload['vitals'])->keyBy('type');
        $this->assertCount(2, $vitals);
        $this->assertSame(2, $vitals['heart_rate']['count']);
        $this->assertSame(72.0, (float) $vitals['heart_rate']['min']);
        $this->assertSame(80.0, (float) $vitals['heart_rate']['max']);
        $this->assertSame(76.0, (float) $vitals['heart_rate']['avg']);
        $this->assertSame(1, $vitals['blood_pressure']['count']);

        $this->assertCount(2, $payload['appointments']);
        $this->assertSame(
            [Appointment::STATUS_ATTENDED, Appointment::STATUS_PLANNED],
            array_column($payload['appointments'], 'status'),
        );
        $this->assertSame(2, $payload['care_tasks']['total_open']);
        $this->assertSame(now()->subDays(30)->toDateString(), $data['period_from']);
        $this->assertSame(now()->toDateString(), $data['period_to']);
    }

    public function test_brf_t02_a_caregiver_with_only_vitals_cannot_widen_the_brief(): void
    {
        // The report-create permission is a profile.basic/create grant; the only
        // *data* scope granted for reading is vitals.
        $this->grant($this->caregiver, ['profile.basic'], ['create']);
        $this->grant($this->caregiver, ['vitals'], ['read']);

        Sanctum::actingAs($this->caregiver);

        $response = $this->postJson($this->reportsPath())->assertCreated();

        $payload = $response->json('data.payload');

        $this->assertSame(['vitals'], $payload['sections']);
        $this->assertArrayNotHasKey('conditions', $payload);
        $this->assertArrayNotHasKey('allergies', $payload);
        $this->assertArrayNotHasKey('medications', $payload);
        $this->assertArrayNotHasKey('appointments', $payload);
        $this->assertArrayNotHasKey('care_tasks', $payload);
        $this->assertStringNotContainsString('Type 2 Diabetes', (string) $response->getContent());
        $this->assertStringNotContainsString('Metformin', (string) $response->getContent());
    }

    public function test_brf_t03_lab_results_mental_health_and_free_text_never_appear(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->reportsPath())->assertCreated();
        $body = (string) $response->getContent();

        foreach ([
            'lab_results',
            'mental_health',
            'CONDITION-NOTE-MARKER',
            'ALLERGY-REACTION-MARKER',
            'MED-INSTRUCTION-MARKER',
            'TASK-TITLE-MARKER',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "Brief leaked {$forbidden}");
        }
    }

    public function test_brf_t04_sharing_returns_a_token_once_and_the_link_expires(): void
    {
        Sanctum::actingAs($this->owner);
        $reportId = $this->createReportViaApi()['id'];

        $response = $this->postJson($this->sharePath($reportId))->assertCreated();
        $token = $response->json('data.token');
        $url = $response->json('data.url');

        $this->assertIsString($token);
        $this->assertSame(43, strlen($token));
        $this->assertSame('/api/v1/briefs/'.$token, $url);
        $this->assertSame(0, preg_match('/[^A-Za-z0-9_-]/', $token));

        $this->useWorkspace();
        $row = DB::table('report_shares')->where('id', $response->json('data.id'))->first();

        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_hash);

        $this->getJson($url)->assertOk();

        // 31 minutes later the link has crossed its 30-minute lifetime.
        $this->travel(31)->minutes();
        $this->getJson($url)
            ->assertNotFound()
            ->assertJsonPath('code', 'out_of_scope');
    }

    public function test_brf_t05_expires_in_minutes_outside_five_to_thirty_is_422(): void
    {
        Sanctum::actingAs($this->owner);
        $reportId = $this->createReportViaApi()['id'];

        $this->postJson($this->sharePath($reportId), ['expires_in_minutes' => 4])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        // Blueprint DB-003 caps a share token at 30 minutes, so 31 and 60 are
        // rejected even though they are "reasonable" lifetimes.
        $this->postJson($this->sharePath($reportId), ['expires_in_minutes' => 31])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->postJson($this->sharePath($reportId), ['expires_in_minutes' => 60])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->postJson($this->sharePath($reportId), ['expires_in_minutes' => 5])
            ->assertCreated();
        $this->postJson($this->sharePath($reportId), ['expires_in_minutes' => 30])
            ->assertCreated();
    }

    public function test_brf_t06_a_revoked_share_is_404_and_revoking_twice_is_idempotent(): void
    {
        Sanctum::actingAs($this->owner);
        $reportId = $this->createReportViaApi()['id'];

        $share = $this->postJson($this->sharePath($reportId))->assertCreated()->json('data');
        $url = $share['url'];

        $this->getJson($url)->assertOk();

        $this->deleteJson($this->shareRevokePath($reportId, $share['id']))->assertNoContent();
        $this->getJson($url)
            ->assertNotFound()
            ->assertJsonPath('code', 'out_of_scope');

        // A second revoke is a successful no-op.
        $this->deleteJson($this->shareRevokePath($reportId, $share['id']))->assertNoContent();

        $this->useWorkspace();
        $row = DB::table('report_shares')->where('id', $share['id'])->first();
        $this->assertNotNull($row->revoked_at);
    }

    public function test_brf_t07_revoking_the_report_kills_every_share(): void
    {
        Sanctum::actingAs($this->owner);
        $reportId = $this->createReportViaApi()['id'];

        $first = $this->postJson($this->sharePath($reportId))->assertCreated()->json('data');
        $second = $this->postJson($this->sharePath($reportId))->assertCreated()->json('data');

        $this->useWorkspace();

        $report = app(HealthReportService::class)->revokeReport(
            $this->workspace,
            $reportId,
            $this->owner,
        );

        $this->assertSame(HealthReport::STATUS_REVOKED, $report->status);
        $this->assertSame(
            2,
            DB::table('report_shares')->where('report_id', $reportId)->count(),
        );

        $this->getJson($first['url'])->assertNotFound()->assertJsonPath('code', 'out_of_scope');
        $this->getJson($second['url'])->assertNotFound()->assertJsonPath('code', 'out_of_scope');

        $this->useWorkspace();
        $this->assertSame(
            0,
            DB::table('report_shares')->where('report_id', $reportId)->whereNull('revoked_at')->count(),
        );
    }

    public function test_brf_t08_each_access_increments_the_counter_and_writes_one_phi_free_audit_row(): void
    {
        Sanctum::actingAs($this->owner);
        $reportId = $this->createReportViaApi()['id'];

        $share = $this->postJson($this->sharePath($reportId))->assertCreated()->json('data');

        for ($i = 0; $i < 3; $i++) {
            $this->getJson($share['url'])->assertOk();
        }

        $this->useWorkspace();

        $row = DB::table('report_shares')->where('id', $share['id'])->first();
        $this->assertSame(3, (int) $row->access_count);
        $this->assertNotNull($row->last_accessed_at);
        $this->assertSame(hash('sha256', '127.0.0.1'), $row->last_accessed_ip);
        $this->assertNotSame('127.0.0.1', $row->last_accessed_ip);

        $logs = DB::table('security_audit_logs')
            ->where('action', 'report.share.accessed')
            ->orderBy('seq')
            ->get(['action', 'target_id', 'target_type', 'actor_type', 'actor_id', 'metadata']);

        $this->assertCount(3, $logs);

        $haystack = $logs->pluck('metadata')->implode(' ');

        foreach ([$share['token'], 'Type 2 Diabetes', 'Metformin', 'Penicillin', 'TASK-TITLE-MARKER'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $haystack, "Access audit leaked {$forbidden}");
        }

        foreach ($logs as $log) {
            $this->assertSame($reportId, $log->target_id);
            $this->assertSame('system', $log->actor_type);
        }
    }

    public function test_brf_t09_public_brief_forbids_caching_and_referrers(): void
    {
        Sanctum::actingAs($this->owner);
        $reportId = $this->createReportViaApi()['id'];
        $share = $this->postJson($this->sharePath($reportId))->assertCreated()->json('data');

        $response = $this->getJson($share['url'])->assertOk();

        // Symfony's ResponseHeaderBag appends ", private" to any cache-control
        // directive set that is neither public nor private, so the emitted value
        // is "no-store, private". Both are non-cacheable; assert the directive.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        // The body is compact JSON, not pretty-printed.
        $this->assertStringNotContainsString("\n", (string) $response->getContent());
    }

    public function test_brf_t10_unknown_and_out_of_scope_tokens_return_the_same_404_body(): void
    {
        $headers = ['X-Request-Id' => 'brf-t10-request'];

        $unknown = $this->withHeaders($headers)->getJson('/api/v1/briefs/'.str_repeat('A', 43));
        $unknown->assertNotFound()->assertJsonPath('code', 'out_of_scope');

        // A token minted in another workspace, whose report has since been
        // revoked. This is the negative counterpart to the public capability:
        // an out-of-scope capability is indistinguishable from an unknown one.
        Sanctum::actingAs($this->otherOwner);
        $otherReportId = $this->postJson($this->reportsPathFor(
            $this->otherWorkspace,
            $this->otherProfile,
        ))->assertCreated()->json('data.id');
        $foreign = $this->postJson($this->sharePathFor(
            $this->otherWorkspace,
            $this->otherProfile,
            $otherReportId,
        ))->assertCreated()->json('data');

        $this->useWorkspace($this->otherWorkspace);
        app(HealthReportService::class)->revokeReport($this->otherWorkspace, $otherReportId, $this->otherOwner);
        $this->useWorkspace();

        $foreignResponse = $this->withHeaders($headers)->getJson($foreign['url']);
        $foreignResponse->assertNotFound()->assertJsonPath('code', 'out_of_scope');

        $this->assertSame($unknown->json(), $foreignResponse->json());
    }

    public function test_brf_t11_a_viewer_cannot_create_a_brief_or_share_one(): void
    {
        Sanctum::actingAs($this->owner);
        $reportId = $this->createReportViaApi()['id'];

        Sanctum::actingAs($this->viewer);

        $this->postJson($this->reportsPath())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->postJson($this->sharePath($reportId))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_brf_t12_regeneration_creates_a_new_row_and_the_old_share_keeps_the_old_snapshot(): void
    {
        Sanctum::actingAs($this->owner);

        $first = $this->createReportViaApi();
        $share = $this->postJson($this->sharePath($first['id']))->assertCreated()->json('data');

        $this->useWorkspace();
        $this->condition->forceFill(['name' => 'Type 2 Diabetes (revised)'])->save();

        $second = $this->createReportViaApi();

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame('Type 2 Diabetes', $first['payload']['conditions'][0]['name']);
        $this->assertSame('Type 2 Diabetes (revised)', $second['payload']['conditions'][0]['name']);

        $this->getJson($share['url'])
            ->assertOk()
            ->assertJsonPath('conditions.0.name', 'Type 2 Diabetes');

        $this->useWorkspace();
        $this->assertSame(2, HealthReport::query()->where('profile_id', $this->profile->id)->count());
    }

    private function useWorkspace(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($this->tenant->id, $workspace->id);
    }

    private function reportsPath(): string
    {
        return $this->reportsPathFor($this->workspace, $this->profile);
    }

    private function reportsPathFor(Workspace $workspace, HealthProfile $profile): string
    {
        return "/api/v1/workspaces/{$workspace->id}/profiles/{$profile->id}/health-reports";
    }

    private function sharePath(string $reportId): string
    {
        return $this->sharePathFor($this->workspace, $this->profile, $reportId);
    }

    private function sharePathFor(Workspace $workspace, HealthProfile $profile, string $reportId): string
    {
        return $this->reportsPathFor($workspace, $profile)."/{$reportId}/share";
    }

    private function shareRevokePath(string $reportId, string $shareId): string
    {
        return $this->reportsPath()."/{$reportId}/shares/{$shareId}";
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function createReportViaApi(array $attributes = []): array
    {
        return $this->postJson($this->reportsPath(), $attributes)
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $actions
     */
    private function grant(User $grantee, array $scopes, array $actions): AccessGrant
    {
        $this->useWorkspace();

        return AccessGrant::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
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

    private function makeExportConsent(HealthProfile $profile, string $subjectPersonId): ConsentRecord
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return ConsentRecord::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'consent_type' => 'data_export',
            'purpose' => 'data_export',
            'scope' => ['profile.basic', 'vitals', 'medications', 'appointments'],
            'policy_version' => 'v1.0.0',
            'consenting_subject_id' => $subjectPersonId,
            'legal_basis_type' => 'consent',
            'granted_at' => now(),
            'withdrawn_at' => null,
            'evidence_reference' => null,
            'superseded_by_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCondition(array $attributes = []): HealthCondition
    {
        $this->useWorkspace();

        return HealthCondition::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'code' => null,
            'name' => 'Type 2 Diabetes',
            'diagnosed_at' => now()->subYears(8),
            'status' => 'active',
            'notes' => 'CONDITION-NOTE-MARKER',
            'version' => 1,
        ], $attributes));
    }

    private function makeAllergy(): Allergy
    {
        $this->useWorkspace();

        return Allergy::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'substance' => 'Penicillin',
            'reaction' => 'ALLERGY-REACTION-MARKER',
            'severity' => 'severe',
            'noted_at' => now()->subYears(3),
            'version' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeMedicationPlan(array $attributes = []): MedicationPlan
    {
        $this->useWorkspace();

        return MedicationPlan::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'name' => 'Metformin',
            'dosage' => '500 mg',
            'form' => 'tablet',
            'route' => 'oral',
            'doses_per_day' => 2,
            'schedule_times' => ['08:00', '20:00'],
            'starts_on' => now()->subYears(2),
            'ends_on' => null,
            'prescribed_by' => 'Dr. Somchai',
            'instructions' => 'MED-INSTRUCTION-MARKER',
            'status' => MedicationPlan::STATUS_ACTIVE,
            'discontinued_reason' => null,
            'discontinued_at' => null,
            'version' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeMeasurement(array $attributes = []): HealthMeasurement
    {
        $this->useWorkspace();

        return HealthMeasurement::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'type' => 'heart_rate',
            'value' => 72,
            'secondary_value' => null,
            'unit' => 'bpm',
            'context' => null,
            'note' => null,
            'recorded_at' => now()->subDay(),
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'version' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeAppointment(array $attributes = []): Appointment
    {
        $this->useWorkspace();

        return Appointment::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'created_by' => $this->owner->id,
            'source' => 'manual',
            'title' => 'Annual check-up',
            'provider_name' => 'Dr. Anna',
            'facility' => 'Bangkok Clinic',
            'purpose' => 'routine visit',
            'location' => 'Room 3',
            'scheduled_at' => Carbon::now()->subDays(10),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_ATTENDED,
            'cancelled_reason' => null,
            'rescheduled_from_id' => null,
            'transported_by_task_id' => null,
            'prepared_documents' => [],
            'follow_up_task_id' => null,
            'version' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCareTask(array $attributes = []): CareTask
    {
        $this->useWorkspace();

        return CareTask::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'created_by' => $this->owner->id,
            'tier' => CareTask::TIER_DELEGATED,
            'type' => 'DAILY_CARE',
            'title' => 'Directly created task',
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
            'review_at' => null,
            'completed_at' => null,
            'idempotency_key' => null,
            'version' => 1,
            'source' => 'manual',
        ], $attributes));
    }
}
