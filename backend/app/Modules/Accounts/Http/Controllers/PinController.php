<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Modules\Accounts\Exceptions\WeakPinException;
use App\Modules\Accounts\Services\PinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PIN setup and verification. The PIN is never echoed in a response or log.
 */
final class PinController extends Controller
{
    public function setup(Request $request, PinService $pins): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $pins->set($user, $validated['pin']);
        } catch (WeakPinException) {
            return ProblemDetails::make(
                $request,
                422,
                'validation',
                'Validation failed',
                'รหัส PIN อ่อนเกินไป กรุณาใช้ตัวเลขที่ไม่ซ้ำหรือเรียงกัน',
            );
        }

        return response()->json(null, 204);
    }

    public function verify(Request $request, PinService $pins): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $result = $pins->verify($user, $validated['pin']);

        if ($result->ok) {
            return response()->json(['data' => ['verified' => true]]);
        }

        if ($result->locked) {
            return ProblemDetails::make(
                $request,
                423,
                'locked',
                'Account locked',
                'PIN ถูกระงับชั่วคราว กรุณาลองใหม่ภายหลัง',
            );
        }

        return ProblemDetails::make(
            $request,
            422,
            'validation',
            'Validation failed',
            'รหัส PIN ไม่ถูกต้อง',
        );
    }
}
