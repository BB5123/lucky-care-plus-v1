<?php

namespace App\Modules\AiGateway\Application\Evaluation;

use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;

/**
 * Deterministic provider used only by `ai:evaluate`.
 *
 * It is not bound in the container and is not reachable from an HTTP request:
 * it exists so the evaluation can feed an exact answer (grounded,
 * ungrounded, missing safety advice, …) into the real guardrail pipeline. No
 * network client, no database.
 */
final class EvaluationProvider implements LlmProvider
{
    public function __construct(private readonly string $content) {}

    public function complete(PromptEnvelope $envelope): LlmResponse
    {
        return new LlmResponse(
            content: $this->content,
            inputTokens: null,
            outputTokens: null,
            model: 'evaluation-provider',
            finishReason: 'stop',
        );
    }
}
