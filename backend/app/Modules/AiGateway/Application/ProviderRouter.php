<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use App\Modules\AiGateway\Domain\ProviderException;
use App\Modules\AiGateway\Models\AiRequest;
use App\Modules\AiGateway\Providers\NullProvider;
use App\Modules\AiGateway\Providers\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Provider selection and circuit breaking (`.ai/08-AI-GATEWAY.md` section 5).
 *
 * The router is the only component that decides whether a request may leave
 * the process. It:
 *
 *  - resolves a provider from configuration per purpose (`summary`, `draft`,
 *    `ocr`), falling back to NullProvider when AI is disabled, when the
 *    configured provider is unknown, or when the HTTP adapter has no endpoint;
 *    `okmd`/`kku` are accepted aliases for the OpenAI-compatible adapter;
 *  - keeps a rolling window of provider outcomes in the cache and opens the
 *    circuit once the error rate crosses the configured threshold;
 *  - allows exactly one half-open probe after the cooldown, closing the
 *    circuit again on success.
 *
 * It never touches the database and never sees a prompt beyond the envelope it
 * forwards to the adapter.
 */
final class ProviderRouter implements LlmProvider
{
    private const CACHE_PREFIX = 'ai:circuit:';

    private string $activeProviderName = 'null';

    public function __construct(
        private readonly NullProvider $nullProvider,
        private readonly OpenAiCompatibleProvider $openAiProvider,
    ) {}

    public function complete(PromptEnvelope $envelope): LlmResponse
    {
        return $this->completeFor(AiRequest::PURPOSE_SUMMARY, $envelope);
    }

    /**
     * Per-purpose entry point. `summary`, `draft` and `ocr` may each name a
     * provider under `ai.purposes.<purpose>.provider`; absent keys inherit
     * `ai.provider`, so the default single-provider deployment needs no extra
     * configuration.
     */
    public function completeFor(string $purpose, PromptEnvelope $envelope): LlmResponse
    {
        $provider = $this->providerFor($purpose);

        if ($provider instanceof NullProvider) {
            return $provider->complete($envelope);
        }

        $providerName = $this->activeProviderName;
        $mode = $this->circuitMode($providerName);

        if ($mode === 'open') {
            throw ProviderException::circuitOpen();
        }

        try {
            $response = $provider->complete($envelope);
        } catch (ProviderException $exception) {
            // A 4xx or a provider refusal proves the provider is reachable;
            // counting it as an availability error would open the circuit for
            // a policy decision. Only timeout/unavailable/invalid responses
            // contribute to the error rate.
            $this->recordOutcome($providerName, ! $exception->countsAsFailure, $mode === 'probe');

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordOutcome($providerName, false, $mode === 'probe');

            throw $exception;
        }

        $this->recordOutcome($providerName, true, $mode === 'probe');

        return $response;
    }

    public function providerFor(string $purpose = AiRequest::PURPOSE_SUMMARY): LlmProvider
    {
        $this->activeProviderName = 'null';

        if (! (bool) config('ai.enabled', false)) {
            return $this->nullProvider;
        }

        $configured = config('ai.purposes.'.$purpose.'.provider');

        if (! is_string($configured) || trim($configured) === '') {
            $configured = config('ai.provider', 'null');
        }

        if (! is_string($configured) || trim($configured) === '') {
            return $this->nullProvider;
        }

        $normalised = strtolower(trim($configured));

        if ($normalised === 'null' || $normalised === 'none') {
            return $this->nullProvider;
        }

        // The OKMD / KKU IntelliSphere gateway speaks the same protocol, so the
        // vendor name is accepted as an alias: `AI_PROVIDER=OKMD` in `.env`
        // must behave exactly like `openai_compatible`.
        if (in_array($normalised, ['openai_compatible', 'okmd', 'kku'], true)) {
            if (! $this->openAiProvider->isConfigured()) {
                return $this->nullProvider;
            }

            $this->activeProviderName = 'openai_compatible';

            return $this->openAiProvider;
        }

        if (is_a($configured, LlmProvider::class, true)) {
            $provider = app($configured);

            if ($provider instanceof LlmProvider) {
                $this->activeProviderName = class_basename($configured);

                return $provider;
            }
        }

        return $this->nullProvider;
    }

    public function activeProviderName(): string
    {
        return $this->activeProviderName;
    }

    /**
     * True while the circuit is open (a half-open probe window is not "open":
     * the caller that wins the probe lock is allowed through).
     */
    public function isCircuitOpen(string $provider = 'openai_compatible'): bool
    {
        $openUntil = Cache::get($this->key($provider, 'open_until'));

        return is_numeric($openUntil) && $this->now() < (int) $openUntil;
    }

    /**
     * @return 'closed'|'open'|'probe'
     */
    private function circuitMode(string $provider): string
    {
        $openUntil = Cache::get($this->key($provider, 'open_until'));

        if (! is_numeric($openUntil)) {
            return 'closed';
        }

        if ($this->now() < (int) $openUntil) {
            return 'open';
        }

        // Cooldown elapsed: exactly one caller wins the probe slot. Everyone
        // else keeps waiting until the probe reports success or failure.
        if (Cache::add($this->key($provider, 'probe'), $this->now(), $this->cooldownSeconds())) {
            return 'probe';
        }

        return 'open';
    }

    private function recordOutcome(string $provider, bool $ok, bool $probe): void
    {
        if ($probe) {
            if ($ok) {
                $this->close($provider);
            } else {
                $this->open($provider);
            }

            return;
        }

        if ($ok) {
            $this->appendOutcome($provider, true);

            return;
        }

        $this->appendOutcome($provider, false);
    }

    private function appendOutcome(string $provider, bool $ok): void
    {
        $now = $this->now();
        $window = $this->windowSeconds();
        $outcomes = Cache::get($this->key($provider, 'outcomes'), []);

        if (! is_array($outcomes)) {
            $outcomes = [];
        }

        $outcomes = array_values(array_filter(
            $outcomes,
            static fn (mixed $outcome): bool => is_array($outcome)
                && is_int($outcome['at'] ?? null)
                && $outcome['at'] >= $now - $window,
        ));

        $outcomes[] = ['at' => $now, 'ok' => $ok];

        Cache::put($this->key($provider, 'outcomes'), $outcomes, max(1, $window * 2));

        $samples = count($outcomes);
        $minimum = max(1, (int) config('ai.circuit_breaker.min_samples', 5));

        if ($samples < $minimum) {
            return;
        }

        $errors = count(array_filter(
            $outcomes,
            static fn (array $outcome): bool => ($outcome['ok'] ?? false) === false,
        ));

        $threshold = (float) config('ai.circuit_breaker.error_threshold', 0.20);

        if (($errors / $samples) > $threshold) {
            $this->open($provider);
        }
    }

    private function open(string $provider): void
    {
        Cache::put(
            $this->key($provider, 'open_until'),
            $this->now() + $this->cooldownSeconds(),
            $this->cooldownSeconds() * 2,
        );
        Cache::forget($this->key($provider, 'probe'));
    }

    private function close(string $provider): void
    {
        Cache::forget($this->key($provider, 'open_until'));
        Cache::forget($this->key($provider, 'probe'));
        Cache::forget($this->key($provider, 'outcomes'));
    }

    private function windowSeconds(): int
    {
        return max(1, (int) config('ai.circuit_breaker.window_seconds', 300));
    }

    private function cooldownSeconds(): int
    {
        return max(1, (int) config('ai.circuit_breaker.cooldown_seconds', 120));
    }

    private function key(string $provider, string $suffix): string
    {
        return self::CACHE_PREFIX.str_replace('\\', '.', $provider).':'.$suffix;
    }

    private function now(): int
    {
        return (int) now()->getTimestamp();
    }
}
