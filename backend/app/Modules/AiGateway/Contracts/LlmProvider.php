<?php

namespace App\Modules\AiGateway\Contracts;

use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;

/**
 * Provider boundary (`.ai/08-AI-GATEWAY.md` section 5).
 *
 * An adapter receives only a scrubbed PromptEnvelope. It must never resolve
 * models, query the database or receive repository services. NullProvider stays
 * the default; ProviderRouter selects the OpenAI-compatible HTTP adapter only
 * when AI is enabled and all connection settings are present.
 */
interface LlmProvider
{
    public function complete(PromptEnvelope $envelope): LlmResponse;
}
