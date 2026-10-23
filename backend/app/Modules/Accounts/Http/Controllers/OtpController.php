<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Modules\Accounts\Services\AuthAudit;
use App\Modules\Accounts\Services\OtpDemoMode;
use App\Modules\Accounts\Services\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phone OTP request/verification for an authenticated account.
 *
 * The plaintext code is never returned by the API and never logged outside
 * local/testing; only `OtpSender` sees it. The single exception is the explicit
 * development demo mode (`OtpDemoMode`), which echoes the code in the response
 * for device testing and is refused in production.
 */
final class OtpController extends Controller
{
    private const PHONE_RULE = ['required', 'string', 'regex:/^\+?[0-9]{9,15}$/'];

    public function request(
        Request $request,
        OtpService $otp,
        OtpDemoMode $demo,
        AuthAudit $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'phone' => self::PHONE_RULE,
        ]);

        /** @var User $user */
        $user = $request->user();

        // A phone number identifies exactly one account.
        $taken = User::query()
            ->where('phone', $validated['phone'])
            ->whereKeyNot($user->id)
            ->exists();

        if ($taken) {
            return ProblemDetails::make(
                $request,
                422,
                'validation',
                'Validation failed',
                'ไม่สามารถใช้หมายเลขโทรศัพท์นี้ได้',
            );
        }

        $code = $otp->request($user, OtpService::PURPOSE_PHONE_VERIFICATION, $validated['phone']);

        $data = [
            'channel' => OtpService::CHANNEL_SMS,
            'expires_in' => OtpService::TTL_MINUTES * 60,
            'message' => 'ส่งรหัสยืนยันแล้ว',
        ];

        // Development aid only: lets a Flutter client finish the flow without a
        // SIM or access to server logs. `OtpDemoMode` refuses to be enabled in
        // production and the issuance is audited so demo use is traceable.
        if ($demo->enabled()) {
            $data['demo_mode'] = true;
            $data['demo_code'] = $code;

            $audit->otpDemoIssued($user);
        }

        return response()->json(['data' => $data], 202);
    }

    public function verify(Request $request, OtpService $otp): JsonResponse
    {
        $validated = $request->validate([
            'phone' => self::PHONE_RULE,
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $verified = $otp->verify(
            $user,
            OtpService::PURPOSE_PHONE_VERIFICATION,
            $validated['code'],
            $validated['phone'],
        );

        if (! $verified) {
            return ProblemDetails::make(
                $request,
                422,
                'validation',
                'Validation failed',
                'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ',
            );
        }

        try {
            $user->forceFill([
                'phone' => $validated['phone'],
                'phone_verified_at' => now(),
            ])->save();
        } catch (QueryException $e) {
            // Another account claimed the number between request and verify.
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }

            return ProblemDetails::make(
                $request,
                422,
                'validation',
                'Validation failed',
                'ไม่สามารถใช้หมายเลขโทรศัพท์นี้ได้',
            );
        }

        return response()->json([
            'data' => [
                'phone' => $user->phone,
                'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            ],
        ]);
    }
}
