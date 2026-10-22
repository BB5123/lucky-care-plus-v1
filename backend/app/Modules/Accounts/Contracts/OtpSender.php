<?php

namespace App\Modules\Accounts\Contracts;

/**
 * Delivery boundary for one-time codes (SMS today, a stub in tests).
 *
 * Implementations must never persist or log a plaintext code outside the local
 * and testing environments (N-02, `.ai/09-SECURITY-PRIVACY.md §2.4`).
 */
interface OtpSender
{
    public function send(string $destination, string $code): void;
}
