<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AiGateway\Application\ProviderRouter;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use App\Modules\AiGateway\Domain\ProviderException;
use App\Modules\AiGateway\Providers\NullProvider;
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
 * It forces `ai.enabled` for the duration of the run. The product flag exists to
 * stop the *product* from sending data while G4/D1 are unsigned; a credential
 * check is not product traffic, and leaving the flag alone made this command
 * report "ok" in 0 ms from the in-process NullProvider without contacting
 * anyone - a false positive on the one thing it is supposed to prove. When no
 * real provider is configured the command now fails instead.
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
        {--max-tokens=32 : Token budget for the ping; a reasoning model needs room to answer}
        {--json : Machine-readable output}';

    protected $description = 'Send one minimal request to the configured AI provider to verify credentials';

    public function handle(ProviderRouter $router): int
    {
        $maxTokens = filter_var(
            $this->option('max-tokens'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($maxTokens === false) {
            $this->error('The --max-tokens option must be a positive integer.');

            return self::FAILURE;
        }

        $overrides = array_filter([
            'ai.provider' => $this->option('provider'),
            'ai.openai_compatible.base_url' => $this->option('base-url'),
            'ai.openai_compatible.model' => $this->option('model'),
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        foreach ($overrides as $key => $value) {
            config([$key => $value]);
        }

        // Same precedent as LiveEvaluationRunner: the measurement forces the
        // flag, because otherwise it measures the stub instead of the provider.
        config(['ai.enabled' => true]);

        $provider = $router->providerFor('summary');
        $resolved = $router->activeProviderName();

        if ($provider instanceof NullProvider) {
            return $this->reportNotConfigured($resolved);
        }

        $envelope = new PromptEnvelope(
            system: 'Reply with the single word: pong.',
            untrustedBlocks: [],
            structuredContext: [],
            locale: 'en',
            maxTokens: $maxTokens,
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
            $errorCode = $exception instanceof ProviderException
                ? (string) $exception->errorCode
                : (method_exists($exception, 'errorCode')
                    ? (string) $exception->errorCode()
                    : class_basename($exception));
            // The message can carry a provider body; strip anything resembling a key.
            $detail = preg_replace('/\b(sk|gsk|thk|or)-[A-Za-z0-9_\-]{6,}/', '[redacted]', $exception->getMessage());

            if ($errorCode === 'provider_empty_content') {
                // The usual cause is a reasoning model that spent the whole
                // budget thinking; say so instead of echoing the raw code twice.
                $detail = 'the model returned no content; a reasoning model may need a larger '
                    ."--max-tokens (this run used {$maxTokens})";
            }
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1e6);

        $payload = [
            'result' => $result,
            'provider' => $resolved,
            'configured_provider' => (string) config('ai.provider'),
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

    /**
     * No credential was exercised, so reporting success would be a false
     * positive. Name the missing configuration instead (never its value).
     */
    private function reportNotConfigured(string $resolved): int
    {
        $detail = 'no provider answered: set AI_PROVIDER and AI_BASE_URL/AI_API_KEY '
            .'(or pass --provider/--base-url) so the check exercises a credential '
            .'instead of the in-process stub';

        $payload = [
            'result' => 'failed',
            'provider' => $resolved,
            'configured_provider' => (string) config('ai.provider'),
            'model' => (string) config('ai.model'),
            'latency_ms' => 0,
            'error_code' => 'provider_not_configured',
            'tokens' => null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload + ['detail' => $detail], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error('failed  provider_not_configured  '.$detail);
        }

        return self::FAILURE;
    }
}
