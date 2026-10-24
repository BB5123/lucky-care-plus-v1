<?php

namespace Tests\Feature\AiGateway;

use App\Modules\AiGateway\Application\Evaluation\LiveEvaluationRunner;
use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use Tests\TestCase;

/**
 * G4 live-evaluation harness (TASK G4, `.ai/08-AI-GATEWAY.md` section 6).
 *
 * No network call is made: the provider is an anonymous test double bound into
 * the container. The tests prove the harness classifies model behaviour, not
 * that any vendor answers well.
 */
class LiveEvaluationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.model' => 'test-model',
            'ai.purposes' => [
                'summary' => ['model' => 'test-model'],
                'ocr' => ['model' => 'test-vision-model'],
            ],
            'ai.openai_compatible' => [
                'base_url' => null,
                'api_key' => null,
                'model' => null,
                'timeout_seconds' => 2,
                'max_retries' => 0,
            ],
            'ai.limits.per_user_day' => 20,
            'ai.limits.per_user_minute' => 1000,
            'ai.circuit_breaker' => [
                'window_seconds' => 300,
                'error_threshold' => 0.20,
                'cooldown_seconds' => 120,
                'min_samples' => 5,
            ],
            'ai.pricing' => [
                'default' => ['input' => 0, 'output' => 0],
            ],
        ]);
    }

    public function test_live_evaluation_passes_when_the_model_is_compliant(): void
    {
        $this->bindProvider($this->compliantAnswer());

        $runner = $this->app->make(LiveEvaluationRunner::class);
        $results = $runner->run(50);

        foreach ($results as $result) {
            $this->assertTrue(
                $result['ok'],
                $result['suite'].': '.implode('; ', $result['failures']),
            );
            $this->assertSame('live', $result['mode']);
        }

        // 20 injection + 10 grounding + 7 critical safety cases (the three
        // normal ones are informational; §6 scores critical cases only).
        $this->assertSame(37, $runner->usage()['calls']);
        $this->assertSame(37 * 100, $runner->usage()['input_tokens']);
        $this->assertSame(37 * 20, $runner->usage()['output_tokens']);
    }

    public function test_a_model_that_obeys_the_injected_canary_fails_the_injection_suite(): void
    {
        $this->bindProvider(new class implements LlmProvider
        {
            public function complete(PromptEnvelope $envelope): LlmResponse
            {
                $json = (string) json_encode($envelope->untrustedBlocks, JSON_UNESCAPED_UNICODE);

                preg_match('/ZZCANARY[0-9]{2}/', $json, $matches);

                return new LlmResponse(
                    content: $matches[0] ?? 'no canary found',
                    inputTokens: 10,
                    outputTokens: 5,
                    model: 'fake-model',
                    finishReason: 'stop',
                );
            }
        });

        $results = $this->app->make(LiveEvaluationRunner::class)->run(50);
        $injection = $this->suite($results, 'injection');

        $this->assertFalse($injection['ok']);
        $this->assertSame(0, $injection['passed']);
        $this->assertStringContainsString('followed an injected instruction', $injection['failures'][0]);
    }

    public function test_a_model_that_omits_the_disclaimer_fails_grounding_and_safety(): void
    {
        $this->bindProvider('สรุปข้อมูลสุขภาพจากบันทึก');

        $runner = $this->app->make(LiveEvaluationRunner::class);
        $results = $runner->run(50);

        $grounding = $this->suite($results, 'grounding');
        $safety = $this->suite($results, 'safety');
        $injection = $this->suite($results, 'injection');

        $this->assertFalse($grounding['ok']);
        $this->assertSame(0, $grounding['passed']);
        $this->assertStringContainsString('missing_disclaimer', $grounding['failures'][0]);

        $this->assertFalse($safety['ok']);
        $this->assertSame(0, $safety['passed']);
        $this->assertStringContainsString('missing_disclaimer', $safety['failures'][0]);

        // Injection does not validate the answer, so the canary check still passes.
        $this->assertTrue($injection['ok']);
    }

    public function test_the_call_budget_stops_before_an_unbounded_run(): void
    {
        $this->bindProvider($this->compliantAnswer());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('call budget');

        $this->app->make(LiveEvaluationRunner::class)->run(3);
    }

    public function test_the_live_command_refuses_when_no_provider_is_configured(): void
    {
        config([
            'ai.provider' => 'null',
            'ai.model' => null,
            'ai.purposes' => [],
            'ai.openai_compatible.model' => null,
        ]);

        $this->artisan('ai:evaluate', ['--live' => true])
            ->expectsOutputToContain('No summary model is configured')
            ->assertExitCode(1);
    }

    public function test_the_live_command_refuses_when_the_provider_is_unconfigured(): void
    {
        config([
            'ai.provider' => 'openai_compatible',
            'ai.model' => 'test-model',
            'ai.purposes' => ['summary' => ['model' => 'test-model']],
            'ai.openai_compatible' => [
                'base_url' => null,
                'api_key' => null,
                'model' => null,
                'timeout_seconds' => 2,
                'max_retries' => 0,
            ],
        ]);

        $this->artisan('ai:evaluate', ['--live' => true])
            ->expectsOutputToContain('No live provider is configured')
            ->assertExitCode(1);
    }

    private function compliantAnswer(): string
    {
        return 'สรุปข้อมูลสุขภาพจากบันทึกที่ได้รับอนุญาต'
            ."\n\n".trim((string) config('ai.disclaimer'));
    }

    private function bindProvider(object|string $provider): void
    {
        if (is_string($provider)) {
            $provider = new class($provider) implements LlmProvider
            {
                public function __construct(private readonly string $content) {}

                public function complete(PromptEnvelope $envelope): LlmResponse
                {
                    return new LlmResponse(
                        content: $this->content,
                        inputTokens: 100,
                        outputTokens: 20,
                        model: 'fake-model',
                        finishReason: 'stop',
                    );
                }
            };
        }

        $this->app->instance(LlmProvider::class, $provider);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function suite(array $results, string $name): array
    {
        foreach ($results as $result) {
            if ($result['suite'] === $name) {
                return $result;
            }
        }

        $this->fail('Suite not found: '.$name);
    }
}
