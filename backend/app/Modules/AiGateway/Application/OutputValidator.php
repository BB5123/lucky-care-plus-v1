<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\InvalidOutputException;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\ValidatedOutput;
use App\Modules\AiGateway\Models\AiRequest;

/**
 * Step 5 — Output Validation & Audit (`.ai/08-AI-GATEWAY.md` section 2).
 *
 * The validator never trusts a provider. It rejects an empty answer, rejects
 * an answer without the mandatory disclaimer, rejects an answer that still
 * contains PII, and rejects any number that is not grounded in the scrubbed
 * context. Only then does it ensure the emergency advice is present for a
 * critical vital and, finally, the gateway writes the single success row;
 * refused, failed and disabled attempts are recorded by the gateway through
 * the same recorder.
 */
final class OutputValidator
{
    public function __construct(
        private readonly PiiScrubber $scrubber,
        private readonly AiRequestRecorder $recorder,
        private readonly GroundingCheck $grounding,
        private readonly SafetyAdvisor $safety,
    ) {}

    /**
     * @param  array<string, mixed>  $context  the scrubbed structure that was sent to the provider
     */
    public function validate(LlmResponse $response, array $context = []): ValidatedOutput
    {
        $content = trim($response->content);

        if ($content === '') {
            throw InvalidOutputException::because('empty_output');
        }

        $disclaimer = trim((string) config('ai.disclaimer'));

        if ($disclaimer === '' || ! str_contains($content, $disclaimer)) {
            throw InvalidOutputException::because('missing_disclaimer');
        }

        if ($this->scrubber->hasResidualPii($content)) {
            throw InvalidOutputException::because('output_pii');
        }

        // Grounding runs on the provider text before the gate appends its own
        // advice, so the advice cannot excuse an invented value.
        $this->grounding->assertGrounded($content, $context);

        return $this->safety->ensureAdvice($content, $context);
    }

    /**
     * @param  list<array{type: string, id: string}>  $recordRefs
     */
    public function recordSuccess(
        GatewayRequest $request,
        LlmResponse $response,
        array $recordRefs,
        string $contextHash,
        int $latencyMs,
        ?string $errorCode = null,
    ): AiRequest {
        return $this->recorder->record(
            request: $request,
            status: AiRequest::STATUS_SUCCEEDED,
            errorCode: $errorCode,
            recordRefs: $recordRefs,
            contextHash: $contextHash,
            latencyMs: $latencyMs,
            usage: [
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
            ],
            model: $response->model,
        );
    }
}
