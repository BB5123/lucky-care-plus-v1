<?php

namespace App\Modules\AiGateway\Providers;

use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use App\Modules\AiGateway\Domain\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * OpenAI-compatible HTTP adapter (`.ai/08-AI-GATEWAY.md` section 5).
 *
 * The adapter is vendor-neutral: it only needs a base URL, a bearer key and a
 * model name, all supplied through environment variables. It receives an
 * already-scrubbed {@see PromptEnvelope}, never touches the database and never
 * logs the envelope or the completion.
 *
 * Failure mapping is deliberately narrow so the gateway can record one stable
 * `error_code` per failure mode:
 *
 *  - timeout / transport failure   → `provider_timeout` (retry up to max_retries)
 *  - HTTP 429 or 5xx               → `provider_unavailable` (retry up to max_retries)
 *  - any other non-2xx             → `provider_rejected` (never retry)
 *  - unparsable/unusable body      → `provider_invalid_response` (never retry)
 *  - a refusal field in the body   → `provider_refused` (never retry)
 */
final class OpenAiCompatibleProvider implements LlmProvider
{
    public function complete(PromptEnvelope $envelope): LlmResponse
    {
        // Defence in depth: the router already refuses to select an HTTP
        // provider while the gate is closed. If a caller reaches the adapter
        // directly, the flag still prevents an outbound request.
        if (! (bool) config('ai.enabled', false)) {
            throw ProviderException::notConfigured();
        }

        if (! $this->isConfigured()) {
            throw ProviderException::notConfigured();
        }

        $attempts = $this->maxRetries() + 1;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = $this->send($envelope);
            } catch (ConnectionException) {
                if ($attempt + 1 < $attempts) {
                    continue;
                }

                throw ProviderException::timeout();
            }

            if ($response->successful()) {
                return $this->mapResponse($response);
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt + 1 < $attempts) {
                    continue;
                }

                throw ProviderException::unavailable();
            }

            // Any other 4xx/3xx is a request-shape decision, not a transient
            // outage: retrying would only duplicate cost.
            throw ProviderException::rejected();
        }

        throw ProviderException::unavailable();
    }

    /**
     * True only when all three connection settings are present. The router
     * uses this to fall back to NullProvider instead of calling half a
     * configuration.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null
            && $this->apiKey() !== null
            && $this->model() !== null;
    }

    private function send(PromptEnvelope $envelope): Response
    {
        return Http::withToken((string) $this->apiKey())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds())
            ->post($this->baseUrl().'/chat/completions', [
                'model' => $this->model(),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $envelope->system,
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->messageContent($envelope),
                    ],
                ],
                'max_tokens' => $envelope->maxTokens,
                'temperature' => $envelope->temperature,
            ]);
    }

    /**
     * Serialise the scrubbed record blocks and non-record metadata as one user
     * message. An image-carrying envelope uses the OpenAI-compatible content
     * parts form and inlines the bytes as a `data:` URL; it never points the
     * provider at a signed or public storage URL (TASK P-3, N-02).
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function messageContent(PromptEnvelope $envelope): string|array
    {
        $text = $this->userContent($envelope);

        if ($envelope->image === null) {
            return $text;
        }

        return [
            [
                'type' => 'text',
                'text' => $text,
            ],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $envelope->image->dataUrl(),
                ],
            ],
        ];
    }

    /**
     * Serialise the scrubbed record blocks and non-record metadata as one
     * text payload. No raw data source is consulted here: the envelope is
     * already the complete, scrubbed input.
     */
    private function userContent(PromptEnvelope $envelope): string
    {
        return json_encode(
            [
                'untrusted_blocks' => $envelope->untrustedBlocks,
                'structured_context' => $envelope->structuredContext,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function mapResponse(Response $response): LlmResponse
    {
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ProviderException::invalidResponse();
        }

        if (! is_array($data)) {
            throw ProviderException::invalidResponse();
        }

        $choice = $data['choices'][0] ?? null;

        if (! is_array($choice) || ! is_array($choice['message'] ?? null)) {
            throw ProviderException::invalidResponse();
        }

        $message = $choice['message'];

        foreach ([$message['refusal'] ?? null, $choice['refusal'] ?? null, $data['refusal'] ?? null] as $refusal) {
            if (is_string($refusal) && trim($refusal) !== '') {
                throw ProviderException::refused();
            }
        }

        $content = $message['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw ProviderException::invalidResponse();
        }

        $model = $data['model'] ?? null;
        $finishReason = $choice['finish_reason'] ?? null;

        return new LlmResponse(
            content: $content,
            inputTokens: $this->tokenCount($data, 'prompt_tokens'),
            outputTokens: $this->tokenCount($data, 'completion_tokens'),
            model: is_string($model) && trim($model) !== '' ? $model : $this->model(),
            finishReason: is_string($finishReason) && trim($finishReason) !== '' ? $finishReason : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tokenCount(array $data, string $key): ?int
    {
        $usage = $data['usage'] ?? null;

        if (! is_array($usage) || ! array_key_exists($key, $usage)) {
            return null;
        }

        $value = $usage[$key];

        if (! is_int($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function baseUrl(): ?string
    {
        $baseUrl = $this->nonEmptyString(config('ai.openai_compatible.base_url'));

        return $baseUrl === null ? null : rtrim($baseUrl, '/');
    }

    private function apiKey(): ?string
    {
        return $this->nonEmptyString(config('ai.openai_compatible.api_key'));
    }

    private function model(): ?string
    {
        return $this->nonEmptyString(config('ai.openai_compatible.model'));
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('ai.openai_compatible.timeout_seconds', 20));
    }

    private function maxRetries(): int
    {
        return max(0, (int) config('ai.openai_compatible.max_retries', 1));
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
