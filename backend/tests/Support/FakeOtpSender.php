<?php

namespace Tests\Support;

use App\Modules\Accounts\Contracts\OtpSender;

/**
 * Captures the code instead of sending it, so tests can complete the OTP flow
 * without reading the application log.
 */
final class FakeOtpSender implements OtpSender
{
    public ?string $lastCode = null;

    public ?string $lastDestination = null;

    public int $sent = 0;

    public function send(string $destination, string $code): void
    {
        $this->lastCode = $code;
        $this->lastDestination = $destination;
        $this->sent++;
    }
}
