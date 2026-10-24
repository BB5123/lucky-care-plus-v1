<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Outcome of the deterministic intake classifier (`.ai/08-AI-GATEWAY.md`
 * section 6, refusal suite).
 *
 * The vocabulary is closed: `proceed` or one of the three refusals. A model
 * never decides this, so the same request always produces the same decision.
 */
final class IntakeDecision
{
    public const PROCEED = 'proceed';

    public const REFUSE_DIAGNOSIS = 'refuse_diagnosis';

    public const REFUSE_PRESCRIBING = 'refuse_prescribing';

    public const REFUSE_EMERGENCY = 'refuse_emergency';

    private function __construct(
        public readonly string $action,
        public readonly ?string $message = null,
        public readonly ?string $matchedPhrase = null,
    ) {}

    public static function proceed(): self
    {
        return new self(self::PROCEED);
    }

    public static function refuse(string $action, string $message, ?string $matchedPhrase = null): self
    {
        return new self($action, $message, $matchedPhrase);
    }

    public function shouldRefuse(): bool
    {
        return $this->action !== self::PROCEED;
    }
}
