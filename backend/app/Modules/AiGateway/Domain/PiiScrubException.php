<?php

namespace App\Modules\AiGateway\Domain;

use RuntimeException;

/**
 * Raised when a scrubbing rule cannot be applied confidently (AIG-003).
 *
 * The message is a machine reason code only. It must never contain the value
 * that failed the rule: that value is health data / PII (N-02).
 */
final class PiiScrubException extends RuntimeException
{
    public static function because(string $reasonCode): self
    {
        return new self($reasonCode);
    }
}
