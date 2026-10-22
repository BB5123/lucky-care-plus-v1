<?php

namespace App\Modules\Accounts\Senders;

use App\Modules\Accounts\Contracts\OtpSender;
use Illuminate\Support\Facades\Log;

/**
 * Development/default sender.
 *
 * The code is logged only in local/testing, where a developer needs it to
 * complete the flow. Every other environment logs the marker and nothing else:
 * no code, no phone number, no user name (N-02, `.ai/09-SECURITY-PRIVACY.md §2.4`).
 */
final class LogOtpSender implements OtpSender
{
    public function send(string $destination, string $code): void
    {
        if (app()->environment(['local', 'testing'])) {
            Log::info('[OTP] verification code issued', ['code' => $code]);

            return;
        }

        Log::info('[OTP] verification code issued');
    }
}
