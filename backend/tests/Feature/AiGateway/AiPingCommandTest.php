<?php

namespace Tests\Feature\AiGateway;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AIP-T01..T03 - `ai:ping` is the post-rotation check for D2.
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
}
