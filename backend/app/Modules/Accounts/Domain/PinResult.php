<?php

namespace App\Modules\Accounts\Domain;

/**
 * Outcome of a PIN verification.
 *
 * `locked` distinguishes the 423 response from a plain 422: the client must
 * stop asking for the PIN until the lock expires (`.ai/MOBILE_DESIGN.md §2.2`).
 */
final readonly class PinResult
{
    private function __construct(
        public bool $ok,
        public bool $locked,
        public int $failedAttempts,
    ) {}

    public static function ok(): self
    {
        return new self(true, false, 0);
    }

    public static function invalid(int $failedAttempts): self
    {
        return new self(false, false, $failedAttempts);
    }

    public static function locked(): self
    {
        return new self(false, true, 5);
    }
}
