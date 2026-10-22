<?php

namespace App\Modules\Notifications\Contracts;

/**
 * Terminal outcome of one channel attempt.
 *
 * `errorCode` is a short machine code (`not_configured`, `sender_error`, …).
 * It must never contain exception messages, recipient data, or PHI (N-02).
 */
final readonly class DeliveryResult
{
    private function __construct(
        public string $status,
        public ?string $errorCode = null,
    ) {}

    public static function sent(): self
    {
        return new self('sent');
    }

    public static function failed(string $errorCode): self
    {
        return new self('failed', $errorCode);
    }
}
