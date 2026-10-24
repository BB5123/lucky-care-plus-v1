<?php

namespace Tests\Feature\AiGateway;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * `ai:evaluate --live` can be pointed at another OpenAI-compatible endpoint.
 *
 * The point is comparing a locally hosted model (a Pathumma GGUF behind a tunnel,
 * see `docs/LOCAL-AI-PATHUMMA.md`) with the production provider using the same
 * suites. The override must stay in memory: nothing may change the stored
 * configuration or open the gate outside the command's own process.
 */
class AiEvaluateOverrideTest extends TestCase
{
    public function test_it_overrides_the_endpoint_and_model_for_a_live_run(): void
    {
        Config::set('ai.provider', 'null');
        Config::set('ai.openai_compatible.base_url', 'https://original.example/v1');
        Config::set('ai.openai_compatible.model', 'original-model');

        $this->artisan('ai:evaluate', [
            '--live' => true,
            '--max-calls' => 1,
            '--base-url' => 'https://tunnel.example/v1/',
            '--model' => 'pathumma-thaillm-8b',
        ]);

        $this->assertSame('openai_compatible', config('ai.provider'));
        $this->assertSame('https://tunnel.example/v1', config('ai.openai_compatible.base_url'));
        $this->assertSame('pathumma-thaillm-8b', config('ai.openai_compatible.model'));
    }

    public function test_it_refuses_a_non_http_base_url_before_calling_anything(): void
    {
        $this->artisan('ai:evaluate', [
            '--live' => true,
            '--base-url' => 'ftp://tunnel.example/v1',
        ])->expectsOutputToContain('--base-url must be an http(s) URL.')
            ->assertExitCode(1);
    }

    public function test_it_leaves_the_offline_gate_closed_and_the_provider_untouched(): void
    {
        Config::set('ai.enabled', false);
        Config::set('ai.provider', 'null');

        $this->artisan('ai:evaluate')->assertExitCode(0);

        $this->assertFalse(config('ai.enabled'));
        $this->assertSame('null', config('ai.provider'));
    }
}
