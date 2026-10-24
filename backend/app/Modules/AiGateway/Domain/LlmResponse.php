<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Provider output. `content` is the only text that ever reaches the user; the
 * gateway never persists it (N-02 / AIG-007).
 */
final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $model = null,
        public readonly ?string $finishReason = null,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }
}
