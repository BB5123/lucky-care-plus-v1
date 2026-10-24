<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Output after step 5. `content` is what the user receives; the two flags are
 * evidence about the guards that ran, never prompt text.
 */
final class ValidatedOutput
{
    /**
     * @param  list<string>  $criticalVitals  vital types that tripped a threshold
     */
    public function __construct(
        public readonly string $content,
        public readonly bool $safetyAdviceAppended = false,
        public readonly array $criticalVitals = [],
    ) {}
}
