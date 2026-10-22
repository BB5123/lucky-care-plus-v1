<?php

namespace App\Modules\Accounts\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum token lifecycle. Tokens are bearer credentials: never log them.
 */
class TokenService
{
    /** `.ai/09-SECURITY-PRIVACY.md §2.3`: tokens live for 30 days. */
    public const TOKEN_TTL_DAYS = 30;

    public function issue(User $user, string $deviceName): string
    {
        $deviceName = trim($deviceName);

        if ($deviceName === '' || mb_strlen($deviceName) > 255) {
            $deviceName = 'api';
        }

        return $user->createToken($deviceName, ['*'], now()->addDays(self::TOKEN_TTL_DAYS))->plainTextToken;
    }

    /**
     * Revoke only the token that authenticated the current request.
     */
    public function revokeCurrent(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
