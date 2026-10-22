<?php

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Accounts\Contracts\OtpSender;
use App\Modules\Accounts\Models\OtpCode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues and verifies six-digit one-time codes.
 *
 * Security properties (`.ai/09-SECURITY-PRIVACY.md §2.3`):
 *  - six digits, random_int (not predictable);
 *  - valid for five minutes;
 *  - single use (consumed_at);
 *  - at most five verification attempts per code;
 *  - a new request invalidates every earlier unconsumed code for the purpose;
 *  - only `hash('sha256', $code)` reaches the database.
 */
class OtpService
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_PHONE_VERIFICATION = 'phone_verification';

    public const PURPOSE_PIN_RESET = 'pin_reset';

    public const CHANNEL_SMS = 'sms';

    public const CODE_LENGTH = 6;

    public const TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    /** @var list<string> */
    private const PURPOSES = [
        self::PURPOSE_LOGIN,
        self::PURPOSE_PHONE_VERIFICATION,
        self::PURPOSE_PIN_RESET,
    ];

    public function __construct(private readonly OtpSender $sender) {}

    /**
     * Create a fresh code for the purpose and hand it to the sender.
     *
     * The plaintext is returned only so the caller can decide what to do with
     * it (the HTTP layer never returns it). After this call the database holds
     * nothing but the hash.
     */
    public function request(User $user, string $purpose, string $destination): string
    {
        $this->assertPurpose($purpose);

        $code = $this->generateCode();

        DB::transaction(function () use ($user, $purpose, $destination, $code): void {
            // Only the newest code may be valid: retire every older one.
            OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            OtpCode::query()->create([
                'user_id' => $user->id,
                'channel' => self::CHANNEL_SMS,
                'destination' => $destination,
                'purpose' => $purpose,
                'code_hash' => $this->hash($code),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'attempts' => 0,
            ]);
        });

        $this->sender->send($destination, $code);

        return $code;
    }

    /**
     * Verify a code against the newest unconsumed code for the purpose.
     *
     * `$destination` is optional for backwards compatibility, but callers that
     * know which phone the code was requested for must pass it so a code
     * issued for one number cannot verify another.
     */
    public function verify(User $user, string $purpose, string $code, ?string $destination = null): bool
    {
        $this->assertPurpose($purpose);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return DB::transaction(function () use ($user, $purpose, $code, $destination): bool {
            $otp = OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $otp instanceof OtpCode) {
                return false;
            }

            if ($destination !== null && ! hash_equals($otp->destination, $destination)) {
                return false;
            }

            // Exhausted codes are dead even when the correct code is supplied.
            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                return false;
            }

            // At the exact expiry instant the code is already dead.
            if (! $otp->expires_at->isFuture()) {
                return false;
            }

            $otp->attempts++;

            if (! hash_equals($otp->code_hash, $this->hash($code))) {
                $otp->save();

                return false;
            }

            $otp->consumed_at = now();
            $otp->save();

            return true;
        });
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('Unknown OTP purpose.');
        }
    }
}
