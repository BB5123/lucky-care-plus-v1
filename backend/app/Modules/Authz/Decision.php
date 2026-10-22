<?php

namespace App\Modules\Authz;

/**
 * Immutable result of an authorization decision.
 *
 * `code()` is the machine-readable reason from
 * `.ai/07-API-CONTRACT.md §2`; `status()` is the HTTP status the transport
 * layer must use. `reason()` is a short internal string and must never carry
 * PHI (N-02).
 */
final readonly class Decision
{
    private function __construct(
        private bool $isAllowed,
        private string $code,
        private int $status,
        private ?string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, 'allowed', 200, null);
    }

    public static function deny(string $code, int $status, ?string $reason = null): self
    {
        return new self(false, $code, $status, $reason);
    }

    public function allowed(): bool
    {
        return $this->isAllowed;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
