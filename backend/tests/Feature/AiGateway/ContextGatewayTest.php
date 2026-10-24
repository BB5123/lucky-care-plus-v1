<?php

namespace Tests\Feature\AiGateway;

use App\Models\User;
use App\Modules\AiGateway\Application\ContextGateway;
use App\Modules\AiGateway\Application\OutputValidator;
use App\Modules\AiGateway\Application\PolicyAndConsentVerifier;
use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\InvalidOutputException;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use App\Modules\AiGateway\Models\AiRequest;
use App\Modules\AiGateway\Models\AiUsageCounter;
use App\Modules\AiGateway\Providers\NullProvider;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * TASK P-1 acceptance suite — AI-T01 ... AI-T10.
 *
 * The HTTP surface is exercised end-to-end so route middleware, the workspace
 * transaction and the five-step gateway are all part of the contract. A
 * CapturingProvider is injected where a test needs to inspect the exact
 * envelope; the default NullProvider is deterministic and never opens a
 * network connection.
 */
class ContextGatewayTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    private User $owner;

    private User $caregiver;

    private User $otherOwner;

    private HealthProfile $profile;

    private HealthProfile $noConsentProfile;

    private HealthProfile $otherProfile;

    private Person $ownerPerson;

    private Person $noConsentPerson;

    private Person $otherPerson;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-24 10:00:00');

        config([
            'ai.enabled' => true,
            'ai.provider' => null,
            'ai.model' => 'null-provider-v1',
            'ai.limits.per_user_day' => 20,
            'ai.limits.per_user_minute' => 5,
        ]);

        $this->tenant = $this->createTenant('ai-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'AI Workspace');
        $this->otherWorkspace = $this->createWorkspace($this->tenant, 'AI Other Workspace');

        $this->owner = User::factory()->create(['name' => 'Owner User']);
        $this->caregiver = User::factory()->create(['name' => 'Caregiver User']);
        $this->otherOwner = User::factory()->create(['name' => 'Other Owner']);

        $this->addMember($this->workspace, $this->owner, 'profile_owner');
        $this->addMember($this->workspace, $this->caregiver, 'caregiver');
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

        $this->noConsentPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'display_name' => 'No Consent Patient',
            'birth_date' => '1970-01-01',
        ]);

        $this->noConsentProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $this->noConsentPerson->id,
            'version' => 1,
        ]);

        $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 72,
            'unit' => 'bpm',
            'recorded_at' => now()->subDay(),
        ]);
        $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 80,
            'unit' => 'bpm',
            'recorded_at' => now(),
        ]);
        $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 120,
            'secondary_value' => 80,
            'unit' => 'mmHg',
            'recorded_at' => now()->subDays(3),
        ]);

        $this->makeMedicationPlan();
        $this->makeAppointment();
        $this->makeTask();

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

        $this->consent($this->profile, $this->ownerPerson->id);

        foreach ([$this->owner, $this->caregiver, $this->otherOwner] as $user) {
            RateLimiter::clear('ai-summary:'.$this->workspace->id.':'.$user->id);
            RateLimiter::clear('ai-summary:'.$this->otherWorkspace->id.':'.$user->id);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ai_t01_disabled_returns_503_and_records_a_disabled_attempt_without_context(): void
    {
        config(['ai.enabled' => false]);

        Sanctum::actingAs($this->owner);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->postJson($this->path())
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_disabled');

        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertStringNotContainsString('health_measurements', $queries);
        $this->assertStringNotContainsString('medication_plans', $queries);
        $this->assertStringNotContainsString('appointments', $queries);
        $this->assertStringNotContainsString('care_tasks', $queries);

        $this->useWorkspace();

        $rows = AiRequest::query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame(AiRequest::STATUS_DISABLED, $rows->first()->status);
        $this->assertSame('ai_disabled', $rows->first()->error_code);
        $this->assertSame([], $rows->first()->record_refs);
        $this->assertSame(hash('sha256', ''), $rows->first()->context_hash);
    }

    public function test_ai_t02_full_authority_owner_gets_summary_disclaimer_and_record_refs(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->path())->assertOk();

        $data = $response->json('data');

        $this->assertStringContainsString(config('ai.disclaimer'), $data['summary']);
        $this->assertSame(config('ai.disclaimer'), $data['disclaimer']);
        $this->assertNotEmpty($data['record_refs']);
        $this->assertSame(1, $data['usage']['requests_today']);
        $this->assertSame(19, $data['usage']['remaining']);
        $this->assertSame('null-provider-v1', $data['model']);

        foreach ($data['record_refs'] as $ref) {
            $keys = array_keys($ref);
            sort($keys);
            $this->assertSame(['id', 'type'], $keys);
        }

        $types = array_values(array_unique(array_column($data['record_refs'], 'type')));
        sort($types);
        $this->assertSame(
            ['appointment', 'care_task', 'health_measurement', 'health_profile', 'medication_plan', 'person'],
            $types,
        );

        $this->useWorkspace();

        $row = AiRequest::query()->sole();
        $this->assertSame(AiRequest::STATUS_SUCCEEDED, $row->status);
        $this->assertSame(64, strlen((string) $row->context_hash));
        $this->assertNotEmpty($row->record_refs);
    }

    public function test_ai_t03_missing_ai_processing_consent_is_403_and_fetches_no_context(): void
    {
        Sanctum::actingAs($this->owner);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->postJson($this->pathFor($this->workspace, $this->noConsentProfile))
            ->assertStatus(403);

        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertSame('no_consent', $response->json('code'));

        foreach (['health_measurements', 'medication_plans', 'appointments', 'care_tasks'] as $table) {
            $this->assertStringNotContainsString($table, $queries, "Consent refusal fetched {$table}");
        }

        $this->useWorkspace();
        $this->assertSame(0, AiRequest::query()->count());
    }

    public function test_ai_t04_caregiver_with_only_vitals_gets_vitals_and_no_other_sections(): void
    {
        $provider = new CapturingProvider;
        $this->app->instance(LlmProvider::class, $provider);

        // ai.report.create needs ai_context/create, ai.health_context.use needs
        // ai_context/read, and the data section needs vitals/read.
        $this->grant($this->caregiver, ['ai_context', 'vitals'], ['create', 'read']);

        Sanctum::actingAs($this->caregiver);

        $response = $this->postJson($this->path())->assertOk();

        $types = array_values(array_unique(array_column($response->json('data.record_refs'), 'type')));
        $this->assertSame(['health_measurement'], $types);

        $this->assertNotNull($provider->lastEnvelope);
        $this->assertSame(
            ['vitals'],
            array_column($provider->lastEnvelope->untrustedBlocks, 'type'),
        );

        foreach (['profile.basic', 'medications', 'appointments', 'tasks'] as $section) {
            $this->assertStringNotContainsString(
                'type="'.$section.'"',
                json_encode($provider->lastEnvelope->untrustedBlocks),
                "Caregiver envelope leaked {$section}",
            );
        }
    }

    public function test_ai_t05_lab_results_and_mental_health_never_enter_the_envelope(): void
    {
        $provider = new CapturingProvider;
        $this->app->instance(LlmProvider::class, $provider);

        // Rows that must never be treated as a vitals section even if an old
        // import wrote a type outside the current enum.
        $this->makeMeasurement([
            'type' => 'lab_results',
            'value' => 1,
            'unit' => 'x',
            'recorded_at' => now(),
        ]);
        $this->makeMeasurement([
            'type' => 'mental_health',
            'value' => 2,
            'unit' => 'x',
            'recorded_at' => now(),
        ]);

        Sanctum::actingAs($this->owner);

        $this->postJson($this->path())->assertOk();

        $this->assertNotNull($provider->lastEnvelope);

        $haystack = json_encode($provider->lastEnvelope->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (['lab_results', 'mental_health', 'lab_report'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $haystack);
        }
    }

    public function test_ai_t06_scrubber_pseudonymizes_name_and_removes_phone_and_national_id(): void
    {
        $provider = new CapturingProvider;
        $this->app->instance(LlmProvider::class, $provider);

        Sanctum::actingAs($this->owner);

        $this->postJson($this->path())->assertOk();

        $this->assertNotNull($provider->lastEnvelope);

        $haystack = json_encode($provider->lastEnvelope->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Somchai Patient', $haystack);
        $this->assertStringNotContainsString('081-234-5678', $haystack);
        $this->assertStringNotContainsString('1-2345-67890-12-3', $haystack);
        $this->assertStringNotContainsString('Dr. Anna', $haystack);
        $this->assertStringContainsString('บุคคล A', $haystack);
        $this->assertStringContainsString('[REDACTED]', $haystack);
        $this->assertStringContainsString('Facility_A', $haystack);
        // Medication names stay: they are clinically necessary.
        $this->assertStringContainsString('Metformin', $haystack);
    }

    public function test_ai_t07_ai_requests_has_no_prompt_output_or_health_value(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->path())->assertOk();
        $summary = $response->json('data.summary');

        $this->useWorkspace();

        $columns = DB::select(
            'select column_name from information_schema.columns '
            ."where table_schema = current_schema() and table_name = 'ai_requests' "
            .'order by ordinal_position'
        );

        $this->assertSame([
            'id',
            'tenant_id',
            'workspace_id',
            'profile_id',
            'actor_id',
            'purpose',
            'provider',
            'model',
            'status',
            'error_code',
            'latency_ms',
            'input_tokens',
            'output_tokens',
            'cost_minor',
            'record_refs',
            'context_hash',
            'created_at',
            'updated_at',
        ], array_map(static fn (object $column): string => $column->column_name, $columns));

        $row = AiRequest::query()->sole();
        $encoded = json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($summary, $encoded);
        $this->assertStringNotContainsString('NullProvider', $encoded);
        $this->assertStringNotContainsString('Somchai Patient', $encoded);
        $this->assertStringNotContainsString('Metformin', $encoded);
        $this->assertStringNotContainsString('blood_pressure', $encoded);

        $this->assertNotEmpty($row->record_refs);

        foreach ($row->record_refs as $ref) {
            $keys = array_keys($ref);
            sort($keys);
            $this->assertSame(['id', 'type'], $keys);
            $this->assertSame(2, count($ref));
        }
    }

    public function test_ai_t08a_daily_quota_21st_request_returns_429(): void
    {
        Sanctum::actingAs($this->owner);

        for ($i = 0; $i < 20; $i++) {
            $this->postJson($this->path())->assertOk();
            $this->travel(1)->minutes();
        }

        $this->postJson($this->path())
            ->assertStatus(429)
            ->assertJsonPath('code', 'ai_quota_exceeded');

        $this->useWorkspace();

        $counter = AiUsageCounter::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('actor_id', $this->owner->id)
            ->whereDate('day', now()->toDateString())
            ->sole();

        $this->assertSame(20, $counter->requests);
    }

    public function test_ai_t08b_minute_quota_6th_request_returns_429(): void
    {
        Sanctum::actingAs($this->owner);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->path())->assertOk();
        }

        $this->postJson($this->path())
            ->assertStatus(429)
            ->assertJsonPath('code', 'ai_quota_exceeded');

        $this->useWorkspace();

        $counter = AiUsageCounter::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('actor_id', $this->owner->id)
            ->whereDate('day', now()->toDateString())
            ->sole();

        $this->assertSame(5, $counter->requests);
    }

    public function test_ai_t09_null_provider_has_disclaimer_and_validator_rejects_empty(): void
    {
        $provider = new NullProvider;
        $response = $provider->complete(new PromptEnvelope(
            system: 'system',
            untrustedBlocks: [],
            structuredContext: [],
            locale: 'th',
            maxTokens: 100,
            temperature: 0.0,
        ));

        $this->assertStringContainsString(config('ai.disclaimer'), $response->content);
        $this->assertFalse($response->isEmpty());

        $validator = $this->app->make(OutputValidator::class);

        try {
            $validator->validate(new LlmResponse('no disclaimer'));
            $this->fail('OutputValidator accepted an answer without the disclaimer.');
        } catch (InvalidOutputException $e) {
            $this->assertSame('missing_disclaimer', $e->getMessage());
        }

        $this->expectException(InvalidOutputException::class);

        $validator->validate(new LlmResponse('   '));
    }

    public function test_ai_t10_health_context_use_requires_the_ai_context_scope(): void
    {
        // A vitals-only grant is a read grant, not an AI grant (F-06).
        $this->grant($this->caregiver, ['vitals'], ['read']);

        $decision = $this->app->make(PolicyAndConsentVerifier::class)->verify(new GatewayRequest(
            workspace: $this->workspace,
            profile: $this->profile,
            actor: $this->caregiver,
            periodFrom: CarbonImmutable::now()->subDays(29)->startOfDay(),
            periodTo: CarbonImmutable::now()->endOfDay(),
        ));

        $this->assertFalse($decision->allowed);
        $this->assertSame('forbidden', $decision->reasonCode);

        Sanctum::actingAs($this->caregiver);

        $this->postJson($this->path())
            ->assertStatus(403);
    }

    public function test_pipeline_refusal_is_recorded_when_the_gateway_is_reached(): void
    {
        // ai.report.create (ai_context/create) passes the route guard, but
        // ai.health_context.use needs ai_context/read. The gateway refuses and
        // still writes exactly one refused attempt.
        $this->grant($this->caregiver, ['ai_context'], ['create']);

        $result = $this->app->make(ContextGateway::class)->run(new GatewayRequest(
            workspace: $this->workspace,
            profile: $this->profile,
            actor: $this->caregiver,
            periodFrom: CarbonImmutable::now()->subDays(29)->startOfDay(),
            periodTo: CarbonImmutable::now()->endOfDay(),
        ));

        $this->assertSame('refused', $result->status);
        $this->assertSame('forbidden', $result->errorCode);

        $this->useWorkspace();

        $row = AiRequest::query()->sole();
        $this->assertSame(AiRequest::STATUS_REFUSED, $row->status);
        $this->assertSame('forbidden', $row->error_code);
        $this->assertSame([], $row->record_refs);
    }

    public function test_diagnosis_question_is_refused_inside_the_gateway_before_any_fetch(): void
    {
        Sanctum::actingAs($this->owner);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->postJson($this->path(), [
            'question' => 'ช่วยวินิจฉัยหน่อยว่าเป็นโรคอะไร',
        ])->assertStatus(422);

        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertSame('refuse_diagnosis', $response->json('code'));
        $this->assertSame('AI request refused', $response->json('title'));

        // The refusal happens before step 2, so no health table is touched.
        foreach (['health_measurements', 'medication_plans', 'appointments', 'care_tasks'] as $table) {
            $this->assertStringNotContainsString($table, $queries, "A refused question fetched {$table}");
        }

        $this->useWorkspace();

        $row = AiRequest::query()->sole();
        $this->assertSame(AiRequest::STATUS_REFUSED, $row->status);
        $this->assertSame('refuse_diagnosis', $row->error_code);
        $this->assertSame([], $row->record_refs);
    }

    public function test_invalid_window_is_422_and_exactly_180_days_is_allowed(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->path(), [
            'period_from' => now()->subDays(181)->toDateString(),
            'period_to' => now()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->postJson($this->path(), [
            'period_from' => now()->toDateString(),
            'period_to' => now()->subDay()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->postJson($this->path(), [
            'period_from' => now()->subDays(180)->toDateString(),
            'period_to' => now()->toDateString(),
        ])->assertOk();
    }

    public function test_cross_workspace_profile_is_not_found(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->pathFor($this->workspace, $this->otherProfile))
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    private function useWorkspace(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($this->tenant->id, $workspace->id);
    }

    private function path(): string
    {
        return $this->pathFor($this->workspace, $this->profile);
    }

    private function pathFor(Workspace $workspace, HealthProfile $profile): string
    {
        return "/api/v1/workspaces/{$workspace->id}/profiles/{$profile->id}/ai/summaries";
    }

    private function consent(HealthProfile $profile, string $subjectPersonId): ConsentRecord
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return ConsentRecord::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'consent_type' => 'ai_processing',
            'purpose' => 'ai_processing',
            'scope' => ['profile.basic', 'vitals', 'medications', 'appointments', 'tasks'],
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

    private function makeMedicationPlan(): MedicationPlan
    {
        $this->useWorkspace();

        return MedicationPlan::query()->create([
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
            'instructions' => 'INSTRUCTION-MARKER',
            'status' => MedicationPlan::STATUS_ACTIVE,
            'discontinued_reason' => null,
            'discontinued_at' => null,
            'version' => 1,
        ]);
    }

    private function makeAppointment(): Appointment
    {
        $this->useWorkspace();

        return Appointment::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'created_by' => $this->owner->id,
            'source' => 'manual',
            'title' => 'AINOTE 081-234-5678 1-2345-67890-12-3',
            'provider_name' => 'Dr. Anna',
            'facility' => 'Bangkok Clinic',
            'purpose' => 'routine visit',
            'location' => 'Room 3',
            'scheduled_at' => now()->subDays(2),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_ATTENDED,
            'cancelled_reason' => null,
            'rescheduled_from_id' => null,
            'transported_by_task_id' => null,
            'prepared_documents' => [],
            'follow_up_task_id' => null,
            'version' => 1,
        ]);
    }

    private function makeTask(): CareTask
    {
        $this->useWorkspace();

        return CareTask::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'created_by' => $this->owner->id,
            'tier' => CareTask::TIER_DELEGATED,
            'type' => 'DAILY_CARE',
            'title' => 'TASK-TITLE-MARKER',
            'description' => 'TASK-DESCRIPTION-MARKER',
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
        ]);
    }
}

/**
 * Test double that records the exact envelope passed to the provider. It is
 * still only a provider: it never touches the database.
 */
final class CapturingProvider implements LlmProvider
{
    public ?PromptEnvelope $lastEnvelope = null;

    public function complete(PromptEnvelope $envelope): LlmResponse
    {
        $this->lastEnvelope = $envelope;

        return new LlmResponse(
            content: 'captured summary'."\n\n".(string) config('ai.disclaimer'),
            inputTokens: 10,
            outputTokens: 20,
            model: 'capturing-provider',
            finishReason: 'stop',
        );
    }
}
