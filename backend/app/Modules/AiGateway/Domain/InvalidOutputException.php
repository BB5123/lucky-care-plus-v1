<?php

namespace App\Modules\AiGateway\Domain;

use RuntimeException;

/**
 * Raised by step 5 when an answer is empty, missing the mandatory disclaimer
 * or still contains PII. The gateway discards the output and records a failed
 * attempt (never retries automatically, `.ai/08-AI-GATEWAY.md` section 4).
 */
final class InvalidOutputException extends RuntimeException
{
    public static function because(string $reasonCode): self
    {
        return new self($reasonCode);
    }
}
