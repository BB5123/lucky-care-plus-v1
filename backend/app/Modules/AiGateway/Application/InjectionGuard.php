<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Domain\InjectionVerdict;
use App\Modules\AiGateway\Domain\PromptEnvelope;

/**
 * Prompt-injection guard for record blocks (`.ai/08-AI-GATEWAY.md` section 2,
 * step 4).
 *
 * `GuardrailPrompting` already wraps every record in `<untrusted_record>`, but
 * wrapping alone does not stop a model from following text such as "ignore
 * previous instructions". Every block is therefore scanned for the override
 * phrases in `config/ai.injection.patterns` and, when one is found, the block
 * is treated as hostile data:
 *
 *  - the matched phrase is replaced with `[NEUTRALIZED_INSTRUCTION]`;
 *  - the whole block is wrapped in
 *    `<neutralized_untrusted_data reason="instruction_override">`, so the
 *    model sees an explicit statement that the following text is data.
 *
 * The block is neutralised rather than refused so a care summary is still
 * produced; the caller records `ai_requests.error_code =
 * injection_neutralized` (and the evaluation report counts it).
 */
final class InjectionGuard
{
    public function inspect(PromptEnvelope $envelope): InjectionVerdict
    {
        $patterns = $this->patterns();

        if ($patterns === []) {
            return new InjectionVerdict($envelope);
        }

        $blocks = [];
        $matches = [];
        $neutralisedTypes = [];

        foreach ($envelope->untrustedBlocks as $block) {
            $type = (string) ($block['type'] ?? 'unknown');
            $content = (string) ($block['content'] ?? '');
            $matched = $this->firstMatch($content, $patterns);

            if ($matched === null) {
                $blocks[] = $block;

                continue;
            }

            $matches[] = $matched;
            $neutralisedTypes[] = $type;

            $blocks[] = [
                'type' => $type,
                'content' => $this->neutralise($content, $matched),
            ];
        }

        return new InjectionVerdict(
            new PromptEnvelope(
                system: $envelope->system,
                untrustedBlocks: $blocks,
                structuredContext: $envelope->structuredContext,
                locale: $envelope->locale,
                maxTokens: $envelope->maxTokens,
                temperature: $envelope->temperature,
            ),
            array_values(array_unique($matches)),
            array_values(array_unique($neutralisedTypes)),
        );
    }

    /**
     * @return list<string>
     */
    private function patterns(): array
    {
        $patterns = [];

        foreach ((array) config('ai.injection.patterns', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function firstMatch(string $content, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (mb_stripos($content, $pattern, 0, 'UTF-8') !== false) {
                return $pattern;
            }
        }

        return null;
    }

    private function neutralise(string $content, string $matched): string
    {
        $replacement = (string) config('ai.injection.replacement', '[NEUTRALIZED_INSTRUCTION]');

        $neutralised = preg_replace(
            '/'.preg_quote($matched, '/').'/iu',
            $replacement,
            $content,
        );

        $neutralised = is_string($neutralised) ? $neutralised : $content;

        return (string) config('ai.injection.marker_open', '<neutralized_untrusted_data reason="instruction_override">')
            .$neutralised
            .(string) config('ai.injection.marker_close', '</neutralized_untrusted_data>');
    }
}
