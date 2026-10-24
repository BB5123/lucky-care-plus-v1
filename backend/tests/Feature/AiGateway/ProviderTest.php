<?php

namespace Tests\Feature\AiGateway;

use App\Models\User;
use App\Modules\AiGateway\Application\ContextGateway;
use App\Modules\AiGateway\Application\CostAccountant;
use App\Modules\AiGateway\Application\Evaluation\EvaluationRunner;
use App\Modules\AiGateway\Application\ProviderRouter;
use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use App\Modules\AiGateway\Domain\ProviderException;
use App\Modules\AiGateway\Models\AiRequest;
use App\Modules\AiGateway\Models\AiUsageCounter;
use App\Modules\AiGateway\Providers\OpenAiCompatibleProvider;
use App\Modules\Audit\Models\SecurityAuditLog;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * TASK P-2 acceptance suite — PRV-T01 ... PRV-T10.
 *
 * Every outbound call is stubbed with `Http::fake`; `preventStrayRequests`
 * makes a forgotten stub fail instead of reaching the network. AI stays
 * disabled in the default configuration and no key is required to run this
 * suite.
 */
class ProviderTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private User $owner;

    private Person $person;

    private HealthProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-24 10:00:00');

        config([
            'ai.enabled' => true,
            'ai.provider' => 'null',
            'ai.model' => 'null-provider-v1',
            'ai.limits.per_user_day' => 20,
            'ai.limits.per_user_minute' => 1000,
            'ai.openai_compatible' => [
                'base_url' => null,
                'api_key' => null,
                'model' => null,
                'timeout_seconds' => 2,
                'max_retries' => 0,
            ],
            'ai.pricing' => [
                'default' => ['input' => 0, 'output' => 0],
            ],
            'ai.circuit_breaker' => [
                'window_seconds' => 300,
                'error_threshold' => 0.20,
                'cooldown_seconds' => 120,
                'min_samples' => 5,
            ],
        ]);

        Cache::flush();
        Http::preventStrayRequests();

        $this->tenant = $this->createTenant('ai-provider-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'AI Provider Workspace');

        $this->owner = User::factory()->create(['name' => 'Provider Owner']);
        $this->addMember($this->workspace, $this->owner, 'profile_owner');

        TenantContext::set($this->tenant->id, $this->workspace->id);

        $this->person = Person::forceCreate([
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
            'person_id' => $this->person->id,
            'version' => 1,
        ]);

        ConsentRecord::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'consent_type' => 'ai_processing',
            'purpose' => 'ai_processing',
            'scope' => ['profile.basic', 'vitals', 'medications', 'appointments', 'tasks'],
            'policy_version' => 'v1.0.0',
            'consenting_subject_id' => $this->person->id,
            'legal_basis_type' => 'consent',
            'granted_at' => now(),
            'withdrawn_at' => null,
            'evidence_reference' => null,
            'superseded_by_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prv_t01_ai_disabled_makes_no_http_request_at_all(): void
    {
        $this->configureOpenAi();
        config(['ai.enabled' => false]);

        Http::fake();

        $result = $this->gateway()->run($this->request());

        $this->assertSame('disabled', $result->status);
        $this->assertSame('ai_disabled', $result->errorCode);

        Http::assertNothingSent();

        $this->useWorkspace();

        $row = AiRequest::query()->sole();
        $this->assertSame(AiRequest::STATUS_DISABLED, $row->status);
        $this->assertSame('ai_disabled', $row->error_code);
    }

    public function test_prv_t02_the_adapter_posts_the_scrubbed_envelope_with_the_bearer_header(): void
    {
        $this->configureOpenAi();

        Http::fake([
            '*' => Http::response($this->completionBody('provider answer'), 200),
        ]);

        $envelope = new PromptEnvelope(
            system: 'You are a summariser.',
            untrustedBlocks: [[
                'type' => 'vitals',
                'content' => '<untrusted_record type="vitals">{"note":"[REDACTED]"}</untrusted_record>',
            ]],
            structuredContext: [
                'period_from' => '2026-10-01',
                'period_to' => '2026-10-24',
                'sections' => ['vitals'],
                'locale' => 'th',
                'pseudonym_count' => 1,
            ],
            locale: 'th',
            maxTokens: 700,
            temperature: 0.2,
        );

        $response = $this->app->make(OpenAiCompatibleProvider::class)->complete($envelope);

        $this->assertSame('test-model', $response->model);
        $this->assertSame(1000, $response->inputTokens);
        $this->assertSame(500, $response->outputTokens);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.example.com/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && ($body['model'] ?? null) === 'test-model'
                && ($body['messages'][0]['role'] ?? null) === 'system'
                && ($body['messages'][0]['content'] ?? null) === 'You are a summariser.'
                && str_contains((string) ($body['messages'][1]['content'] ?? ''), '[REDACTED]')
                && str_contains((string) ($body['messages'][1]['content'] ?? ''), 'period_from')
                && str_contains((string) ($body['messages'][1]['content'] ?? ''), 'vitals')
                && ! str_contains((string) ($body['messages'][1]['content'] ?? ''), '081-234-5678')
                && ($body['max_tokens'] ?? null) === 700
                && ($body['temperature'] ?? null) === 0.2;
        });
    }

    public function test_prv_t03_success_records_tokens_model_cost_and_increments_the_counter(): void
    {
        config([
            'ai.pricing' => [
                'default' => ['input' => 0, 'output' => 0],
                'test-model' => ['input' => 10, 'output' => 20],
            ],
        ]);

        $this->configureOpenAi();

        Http::fake([
            '*' => Http::response($this->completionBody($this->validContent(), 1000, 500), 200),
        ]);

        $result = $this->gateway()->run($this->request());

        $this->assertSame('succeeded', $result->status);
        $this->assertSame('test-model', $result->model);

        $this->useWorkspace();

        $row = AiRequest::query()->sole();
        $this->assertSame(AiRequest::STATUS_SUCCEEDED, $row->status);
        $this->assertSame('test-model', $row->model);
        $this->assertSame(1000, $row->input_tokens);
        $this->assertSame(500, $row->output_tokens);
        $this->assertSame(20, $row->cost_minor);

        $counter = $this->counter();

        $this->assertSame(1, $counter->requests);
        $this->assertSame(1500, $counter->tokens);
        $this->assertSame(20, $counter->cost_minor);

        $this->assertSame(
            20,
            $this->app->make(CostAccountant::class)->monthlyWorkspaceTotal($this->workspace->id),
        );
    }

    public function test_prv_t04_timeout_maps_to_provider_timeout_and_stores_no_partial_output(): void
    {
        $this->configureOpenAi();

        $attempts = 0;

        Http::fake([
            '*' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('Connection timed out');
            },
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->path())
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_timeout');

        $this->assertSame(
            config('ai.provider_messages.provider_timeout'),
            $response->json('detail'),
        );

        // max_retries is zero in this test: the transport failure is reported
        // on the first attempt.
        $this->assertSame(1, $attempts);

        $this->useWorkspace();

        $row = AiRequest::query()->sole();
        $this->assertSame(AiRequest::STATUS_FAILED, $row->status);
        $this->assertSame('provider_timeout', $row->error_code);
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->cost_minor);
    }

    public function test_prv_t05a_429_and_5xx_map_to_provider_unavailable(): void
    {
        $this->configureOpenAi();

        Http::fakeSequence('*')
            ->push(['error' => 'rate limited'], 429)
            ->push(['error' => 'upstream'], 500)
            ->whenEmpty(Http::response(['error' => 'upstream'], 500));

        $this->assertSame('provider_unavailable', $this->gateway()->run($this->request())->errorCode);
        $this->assertSame('provider_unavailable', $this->gateway()->run($this->request())->errorCode);

        Http::assertSentCount(2);

        $this->useWorkspace();

        $this->assertSame(
            ['provider_unavailable'],
            AiRequest::query()->pluck('error_code')->unique()->values()->all(),
        );
    }

    public function test_prv_t05b_400_maps_to_provider_rejected_without_retry(): void
    {
        $this->configureOpenAi(['max_retries' => 1]);

        Http::fake(['*' => Http::response(['error' => 'bad request'], 400)]);

        $result = $this->gateway()->run($this->request());

        $this->assertSame('provider_rejected', $result->errorCode);
        $this->assertSame(503, $result->httpStatus);

        // max_retries allows transient failures to retry, never a request-shape
        // rejection.
        Http::assertSentCount(1);
    }

    public function test_prv_t06_five_failures_open_the_circuit_and_the_next_call_is_503_without_http(): void
    {
        $this->configureOpenAi();
        Http::fake(['*' => Http::response(['error' => 'upstream'], 500)]);

        Sanctum::actingAs($this->owner);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($this->path())
                ->assertStatus(503)
                ->assertJsonPath('code', 'provider_unavailable');
        }

        Http::assertSentCount(5);
        $this->assertTrue($this->app->make(ProviderRouter::class)->isCircuitOpen());

        $response = $this->postJson($this->path())
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_provider_unavailable');

        $this->assertStringContainsString('AI', (string) $response->json('detail'));
        Http::assertSentCount(5);
    }

    public function test_prv_t07_after_the_cooldown_one_probe_is_allowed_and_success_closes_the_circuit(): void
    {
        $this->configureOpenAi();

        $sequence = Http::fakeSequence('*');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $sequence->push(['error' => 'upstream'], 500);
        }

        $sequence
            ->push($this->completionBody($this->validContent()), 200)
            ->push($this->completionBody($this->validContent()), 200)
            ->whenEmpty(Http::response($this->completionBody($this->validContent()), 200));

        Sanctum::actingAs($this->owner);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($this->path())
                ->assertStatus(503)
                ->assertJsonPath('code', 'provider_unavailable');
        }

        Http::assertSentCount(5);
        $this->assertTrue($this->app->make(ProviderRouter::class)->isCircuitOpen());

        $this->travel(121)->seconds();

        $this->postJson($this->path())
            ->assertOk()
            ->assertJsonPath('data.model', 'test-model');

        // Exactly one probe leaves the process after the cooldown.
        Http::assertSentCount(6);
        $this->assertFalse($this->app->make(ProviderRouter::class)->isCircuitOpen());

        $this->postJson($this->path())->assertOk();
        Http::assertSentCount(7);
    }

    public function test_prv_t08_pii_in_the_completion_is_discarded_audited_and_not_stored(): void
    {
        $this->configureOpenAi();

        $content = 'contact 081-234-5678'."\n\n".$this->disclaimer();

        Http::fake(['*' => Http::response($this->completionBody($content), 200)]);

        $result = $this->gateway()->run($this->request());

        $this->assertSame('failed', $result->status);
        $this->assertSame('pii_in_output', $result->errorCode);
        $this->assertSame(503, $result->httpStatus);
        $this->assertNull($result->summary);

        $this->useWorkspace();

        $row = AiRequest::query()->sole();
        $this->assertSame(AiRequest::STATUS_FAILED, $row->status);
        $this->assertSame('pii_in_output', $row->error_code);
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->cost_minor);

        $this->assertStringNotContainsString(
            '081-234-5678',
            (string) json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE),
        );

        $audit = SecurityAuditLog::query()->where('action', 'ai.output.pii')->sole();

        $this->assertSame('pii_in_output', $audit->reason_code);
        $this->assertSame('error', $audit->result);
        $this->assertSame($row->id, $audit->target_id);
    }

    public function test_prv_t09_unconfigured_provider_falls_back_to_null_provider(): void
    {
        config([
            'ai.provider' => 'openai_compatible',
            'ai.openai_compatible' => [
                'base_url' => null,
                'api_key' => null,
                'model' => null,
                'timeout_seconds' => 20,
                'max_retries' => 1,
            ],
        ]);

        Http::fake();

        $result = $this->gateway()->run($this->request());

        $this->assertSame('succeeded', $result->status);
        $this->assertStringContainsString($this->disclaimer(), (string) $result->summary);
        $this->assertSame('null-provider-v1', $result->model);

        Http::assertNothingSent();

        $this->useWorkspace();

        $row = AiRequest::query()->sole();
        $this->assertSame('null', $row->provider);
        $this->assertSame('null-provider-v1', $row->model);
        $this->assertSame(AiRequest::STATUS_SUCCEEDED, $row->status);
    }

    public function test_refusal_and_malformed_body_map_to_their_codes_without_retry(): void
    {
        $this->configureOpenAi(['max_retries' => 1]);

        Http::fakeSequence('*')
            ->push([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that.'],
                    'finish_reason' => 'stop',
                ]],
            ], 200)
            ->push('this is not json', 200)
            ->whenEmpty(Http::response([], 200));

        $provider = $this->app->make(OpenAiCompatibleProvider::class);
        $envelope = new PromptEnvelope(
            system: 'system',
            untrustedBlocks: [],
            structuredContext: [],
            locale: 'th',
            maxTokens: 100,
            temperature: 0.0,
        );

        try {
            $provider->complete($envelope);
            $this->fail('A refusal was accepted as a completion.');
        } catch (ProviderException $exception) {
            $this->assertSame('provider_refused', $exception->errorCode);
        }

        try {
            $provider->complete($envelope);
            $this->fail('A malformed body was accepted as a completion.');
        } catch (ProviderException $exception) {
            $this->assertSame('provider_invalid_response', $exception->errorCode);
        }

        // max_retries=1 still allows no retry for these two cases.
        Http::assertSentCount(2);
    }

    public function test_prv_t10_the_evaluation_suite_still_passes_with_the_router_in_place(): void
    {
        $this->assertInstanceOf(ProviderRouter::class, $this->app->make(LlmProvider::class));

        foreach ($this->app->make(EvaluationRunner::class)->runAll() as $result) {
            $this->assertTrue(
                $result['ok'],
                $result['suite'].': '.implode('; ', $result['failures']),
            );
        }
    }

    private function configureOpenAi(array $overrides = []): void
    {
        config([
            'ai.provider' => 'openai_compatible',
            'ai.model' => 'test-model',
            'ai.openai_compatible' => array_merge([
                'base_url' => 'https://api.example.com/v1',
                'api_key' => 'test-key',
                'model' => 'test-model',
                'timeout_seconds' => 2,
                'max_retries' => 0,
            ], $overrides),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function completionBody(string $content, int $inputTokens = 1000, int $outputTokens = 500): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'test-model',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
    }

    private function validContent(): string
    {
        return 'summary from provider'."\n\n".$this->disclaimer();
    }

    private function disclaimer(): string
    {
        return trim((string) config('ai.disclaimer'));
    }

    private function gateway(): ContextGateway
    {
        return $this->app->make(ContextGateway::class);
    }

    private function request(): GatewayRequest
    {
        return new GatewayRequest(
            workspace: $this->workspace,
            profile: $this->profile,
            actor: $this->owner,
            periodFrom: CarbonImmutable::now()->subDays(29)->startOfDay(),
            periodTo: CarbonImmutable::now()->endOfDay(),
            purpose: AiRequest::PURPOSE_SUMMARY,
            locale: 'th',
        );
    }

    private function counter(): AiUsageCounter
    {
        return AiUsageCounter::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('actor_id', $this->owner->id)
            ->whereDate('day', now()->toDateString())
            ->sole();
    }

    private function path(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/profiles/{$this->profile->id}/ai/summaries";
    }

    private function useWorkspace(): void
    {
        TenantContext::set($this->tenant->id, $this->workspace->id);
    }
}
