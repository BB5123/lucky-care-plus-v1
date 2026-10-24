<?php

namespace App\Modules\AiGateway\Application;

/**
 * Resolves the model id for one AI purpose (`.ai/08-AI-GATEWAY.md` section 5).
 *
 * The adapter sends exactly one model per request, so the model travels on the
 * prompt envelope. Resolution order:
 *
 *   1. `ai.purposes.<purpose>.model` — a purpose-specific override;
 *   2. `ai.openai_compatible.model` — the deployment default;
 *   3. `ai.model` — the historical key.
 *
 * Summaries therefore use a cheap text model while vision extraction uses a
 * model that accepts images, without duplicating the fallback chain in every
 * caller. The resolver never invents a model: an empty result means the
 * deployment has no model configured and the caller must fail closed.
 */
final class ModelResolver
{
    public function forPurpose(string $purpose): ?string
    {
        foreach ([
            config('ai.purposes.'.$purpose.'.model'),
            config('ai.openai_compatible.model'),
            config('ai.model'),
        ] as $candidate) {
            $model = $this->normalise($candidate);

            if ($model !== null) {
                return $model;
            }
        }

        return null;
    }

    private function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
