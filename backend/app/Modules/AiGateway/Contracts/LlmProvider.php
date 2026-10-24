<?php

namespace App\Modules\AiGateway\Contracts;

use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;

/**
 * Provider boundary (`.ai/08-AI-GATEWAY.md` section 5).
 *
 * An adapter receives only a scrubbed PromptEnvelope. It must never resolve
 * models, query the database or receive repository services. In this slice the
 * only implementation is the deterministic in-process NullProvider; no
 * outbound HTTP client is wired.
 */
interface LlmProvider
{
    public function complete(PromptEnvelope $envelope): LlmResponse;
}
