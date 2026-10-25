<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AiGateway\Application\ProviderRouter;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use Illuminate\Console\Command;
use Throwable;

/**
 * One tiny call per configured provider, for post-rotation verification.
 *
 * Rotating an API key (D2) is only finished when the *new* key answers. Doing that
 * by hand means a curl recipe, a base URL and a model name remembered from a doc -
 * `ai:ping` does it with the application's own adapter, so it also proves the
 * gateway path (retries, timeouts, error codes) rather than the provider's.
 *
 * It never prints the key, the base URL's query string, or the reply text: the
 * answer is a health check, not a record of anything a patient said.
 */
final class AiPingCommand extends Command
{
    protected $signature = 'ai:ping
        {--provider= : Override ai.provider for this run (for example openai_compatible)}
        {--base-url= : Override the base URL for this run}
        {--model= : Override the model for this run}
        {--json : Machine-readable output}';

    protected $description = 'Send one minimal request to the configured AI provider to verify credentials';

    public function handle(ProviderRouter $router): int
    {
        $overrides = array_filter([
            'ai.provider' => $this->option('provider'),
            'ai.openai_compatible.base_url' => $this->option('base-url'),
            'ai.openai_compatible.model' => $this->option('model'),
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        foreach ($overrides as $key => $value) {
            config([$key => $value]);
        }

        $envelope = new PromptEnvelope(
            system: 'Reply with the single word: pong.',
            untrustedBlocks: [],
            structuredContext: [],
            locale: 'en',
            maxTokens: 8,
            temperature: 0.0,
        );

        $started = hrtime(true);
        $result = 'ok';
        $errorCode = null;
        $detail = null;
        $tokens = null;

        try {
            $response = $router->completeFor('summary', $envelope);
            $tokens = [
                'input' => $response->inputTokens,
                'output' => $response->outputTokens,
            ];
        } catch (Throwable $exception) {
            $result = 'failed';
            $errorCode = method_exists($exception, 'errorCode')
                ? (string) $exception->errorCode
                : class_basename($exception);
            // The message can carry a provider body; strip anything resembling a key.
            $detail = preg_replace('/\b(sk|gsk|thk|or)-[A-Za-z0-9_\-]{6,}/', '[redacted]', $exception->getMessage());
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1e6);

        $payload = [
            'result' => $result,
            'provider' => (string) config('ai.provider'),
            'model' => (string) config('ai.openai_compatible.model'),
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
            'tokens' => $tokens,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload + ['detail' => $detail], JSON_UNESCAPED_SLASHES));
        } elseif ($result === 'ok') {
            $this->info(sprintf(
                'ok  %s / %s  %dms  in=%s out=%s',
                $payload['provider'],
                $payload['model'],
                $latencyMs,
                $tokens['input'] ?? '?',
                $tokens['output'] ?? '?',
            ));
        } else {
            $this->error(sprintf('failed  %s  %s', $errorCode, $detail ?? ''));
        }

        return $result === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
