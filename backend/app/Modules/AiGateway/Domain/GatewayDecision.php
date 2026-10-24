<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Result of step 1 (Policy & Consent Verifier). A refusal never carries the
 * data that would have been fetched: the caller only sees a reason code and a
 * safe HTTP status.
 */
final class GatewayDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reasonCode,
        public readonly int $httpStatus,
    ) {}

    public static function allow(): self
    {
        return new self(true, null, 200);
    }

    public static function refuse(string $reasonCode, int $httpStatus = 403): self
    {
        return new self(false, $reasonCode, $httpStatus);
    }
}
