<?php

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Accounts\Domain\PinResult;
use App\Modules\Accounts\Exceptions\WeakPinException;
use Illuminate\Support\Facades\Hash;

/**
 * Six-digit PIN storage and verification with lockout.
 *
 * Rules (`.ai/09-SECURITY-PRIVACY.md §2.3`, `.ai/MOBILE_DESIGN.md §2.2`):
 *  - six digits, hashed with the application hasher, never logged or returned;
 *  - weak PINs (repeated or sequential digits) are rejected at setup;
 *  - five consecutive failures lock verification for fifteen minutes;
 *  - a successful verification resets the counter.
 */
class PinService
{
    public const LOCKOUT_THRESHOLD = 5;

    public const LOCKOUT_MINUTES = 15;

    public function set(User $user, string $pin): void
    {
        $this->assertStrong($pin);

        $user->forceFill([
            'pin_hash' => Hash::make($pin),
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();
    }

    public function verify(User $user, string $pin): PinResult
    {
        if (! preg_match('/^\d{6}$/', $pin)) {
            return PinResult::invalid($user->pin_failed_attempts);
        }

        if ($user->pin_locked_until !== null && $user->pin_locked_until->isFuture()) {
            return PinResult::locked();
        }

        // The lock has elapsed: the user gets a clean slate.
        if ($user->pin_locked_until !== null) {
            $user->forceFill([
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
            ]);
        }

        if (! is_string($user->pin_hash) || $user->pin_hash === '') {
            $user->save();

            return PinResult::invalid(0);
        }

        if (Hash::check($pin, $user->pin_hash)) {
            $user->forceFill([
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
            ])->save();

            return PinResult::ok();
        }

        $attempts = $user->pin_failed_attempts + 1;

        $user->pin_failed_attempts = $attempts;

        if ($attempts >= self::LOCKOUT_THRESHOLD) {
            $user->pin_locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
        }

        $user->save();

        // The failure that reaches the threshold is still reported as an
        // invalid PIN; the following attempt sees the lock and returns 423.
        return PinResult::invalid($attempts);
    }

    private function assertStrong(string $pin): void
    {
        if (! preg_match('/^\d{6}$/', $pin)) {
            throw WeakPinException::make();
        }

        if ($this->isRepeated($pin) || $this->isSequential($pin)) {
            throw WeakPinException::make();
        }
    }

    private function isRepeated(string $pin): bool
    {
        return count(array_unique(str_split($pin))) === 1;
    }

    private function isSequential(string $pin): bool
    {
        $digits = array_map('intval', str_split($pin));

        $ascending = true;
        $descending = true;

        for ($i = 1; $i < count($digits); $i++) {
            $step = $digits[$i] - $digits[$i - 1];

            if ($step !== 1) {
                $ascending = false;
            }

            if ($step !== -1) {
                $descending = false;
            }
        }

        return $ascending || $descending;
    }
}
