<?php

namespace App\Modules\Accounts\Services;

use RuntimeException;

/**
 * Guard for the OTP demo mode (`config/otp.php`).
 *
 * The demo mode exists so a Flutter client can complete the OTP flow without an
 * SMS provider: the request endpoint returns the code it just issued. That is
 * safe only outside production, so the guard fails **closed and loudly**:
 *
 *  - `enabled()` returns false unless the flag is on and the environment is not
 *    production;
 *  - if the flag is on in production, the first check throws instead of quietly
 *    disabling it — a deployment must not believe it is protected when it is not.
 *
 * Callers must treat `enabled()` as the single source of truth and must never
 * read the config key directly.
 */
final class OtpDemoMode
{
    public function enabled(): bool
    {
        $configured = (bool) config('otp.demo_mode', false);

        if (! $configured) {
            return false;
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'OTP_DEMO_MODE must not be enabled in production: it returns one-time codes in API responses.'
            );
        }

        return true;
    }
}
