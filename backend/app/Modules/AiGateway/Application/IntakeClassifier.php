<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Domain\IntakeDecision;

/**
 * Rule-based classifier over the user's own request text.
 *
 * The gateway must refuse three categories before it fetches context or calls
 * a provider (`.ai/08-AI-GATEWAY.md` section 6):
 *
 *  - a request for a diagnosis or a confirmation of a disease;
 *  - a request to change, stop or choose a medication dose;
 *  - a request that describes a medical emergency.
 *
 * This is intentionally a phrase list from `config/ai.php`, not a model call:
 * it is explainable, deterministic and can be evaluated with no network. The
 * phrase lists are permissive on purpose — a false refusal is a safe failure,
 * while a missed emergency is not.
 */
final class IntakeClassifier
{
    /** Evaluation order: the most urgent refusal wins. */
    private const CATEGORIES = [
        IntakeDecision::REFUSE_EMERGENCY => 'emergency',
        IntakeDecision::REFUSE_PRESCRIBING => 'prescribing',
        IntakeDecision::REFUSE_DIAGNOSIS => 'diagnosis',
    ];

    public function classify(?string $requestText): IntakeDecision
    {
        $text = trim((string) $requestText);

        if ($text === '') {
            return IntakeDecision::proceed();
        }

        foreach (self::CATEGORIES as $action => $key) {
            $phrase = $this->firstMatch($text, $key);

            if ($phrase === null) {
                continue;
            }

            return IntakeDecision::refuse(
                $action,
                (string) config('ai.intake.messages.'.$action, ''),
                $phrase,
            );
        }

        return IntakeDecision::proceed();
    }

    private function firstMatch(string $text, string $key): ?string
    {
        foreach ((array) config('ai.intake.'.$key, []) as $phrase) {
            if (! is_string($phrase) || $phrase === '') {
                continue;
            }

            if (mb_stripos($text, $phrase, 0, 'UTF-8') !== false) {
                return $phrase;
            }
        }

        return null;
    }
}
