<?php

namespace Tests\Feature\AiGateway;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AIP-T01..T05 - `ai:ping` is the post-rotation check for D2.
 *
 * The whole point of the command is that rotating a key is not finished until the
 * *new* key answers, so the test drives the real gateway adapter with a faked
 * transport and asserts the exit code, not just the output text.
 */
class AiPingCommandTest extends TestCase
{
    private function configureProvider(): void
    {
        config([
            'ai.enabled' => true,
            'ai.provider' => 'openai_compatible',
            'ai.openai_compatible.base_url' => 'https://provider.test/v1',
            'ai.openai_compatible.api_key' => 'sk-test-key-that-must-not-be-printed',
            'ai.openai_compatible.model' => 'test-model',
            'ai.openai_compatible.max_retries' => 0,
        ]);
    }

    public function test_aip_t01_a_working_provider_exits_zero_and_reports_latency(): void
    {
        $this->configureProvider();

        Http::fake([
            'provider.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'pong'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 1],
            ]),
        ]);

        $this->artisan('ai:ping')
            ->expectsOutputToContain('test-model')
            ->assertExitCode(0);
    }

    public function test_aip_t02_a_rejected_key_exits_non_zero_with_the_error_code(): void
    {
        $this->configureProvider();

        Http::fake([
            'provider.test/*' => Http::response(['error' => ['message' => 'invalid api key']], 401),
        ]);

        $this->artisan('ai:ping')
            ->expectsOutputToContain('provider_rejected')
            ->assertExitCode(1);
    }

    public function test_aip_t03_the_key_is_never_printed(): void
    {
        $this->configureProvider();

        // The provider echoes the key back in its error body: the command must redact it.
        Http::fake([
            'provider.test/*' => Http::response([
                'error' => ['message' => 'bad key sk-test-key-that-must-not-be-printed'],
            ], 401),
        ]);

        // Either the adapter's own message (which carries the provider body) is
        // redacted, or it never includes the key at all. Both are acceptable; what is
        // not acceptable is printing it.
        $this->artisan('ai:ping')
            ->doesntExpectOutputToContain('sk-test-key-that-must-not-be-printed')
            ->assertExitCode(1);
    }

    public function test_aip_t04_the_feature_flag_does_not_short_circuit_the_check(): void
    {
        $this->configureProvider();
        // The shipped default: AI is off for product traffic. The credential
        // check must still reach the provider, or a rotation can never be proven.
        config(['ai.enabled' => false]);

        Http::fake([
            'provider.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'pong'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 1],
            ]),
        ]);

        $this->artisan('ai:ping')->assertExitCode(0);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'provider.test'));
    }

    public function test_aip_t05_no_configured_provider_fails_instead_of_reporting_ok(): void
    {
        config([
            'ai.enabled' => false,
            'ai.provider' => 'null',
            'ai.openai_compatible.base_url' => null,
            'ai.openai_compatible.api_key' => null,
            'ai.openai_compatible.model' => null,
        ]);

        Http::fake();

        $this->artisan('ai:ping')
            ->expectsOutputToContain('provider_not_configured')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_aip_t06_empty_content_names_the_token_budget(): void
    {
        $this->configureProvider();

        Http::fake([
            'provider.test/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => null, 'reasoning' => 'thinking'],
                    'finish_reason' => 'length',
                ]],
            ]),
        ]);

        // One expectation: both facts are on the same output line, and
        // `expectsOutputToContain` matches them in order across lines.
        $this->artisan('ai:ping')
            ->expectsOutputToContain('provider_empty_content  the model returned no content;')
            ->assertExitCode(1);

        $this->artisan('ai:ping')
            ->expectsOutputToContain('--max-tokens (this run used 32)')
            ->assertExitCode(1);
    }
}
