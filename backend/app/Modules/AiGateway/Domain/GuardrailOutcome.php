<?php

namespace App\Modules\AiGateway\Domain;

use App\Modules\AiGateway\Application\GuardrailPipeline;

/**
 * Everything steps 3–5 produced (see {@see GuardrailPipeline}).
 *
 * The outcome keeps the scrubbed context and the envelope so the caller can
 * hash the exact context that was sent and so the evaluation can inspect it
 * without a second run.
 */
final class GuardrailOutcome
{
    public function __construct(
        public readonly ScrubbedContext $scrubbed,
        public readonly PromptEnvelope $envelope,
        public readonly InjectionVerdict $injection,
        public readonly LlmResponse $response,
        public readonly ValidatedOutput $output,
    ) {}
}
