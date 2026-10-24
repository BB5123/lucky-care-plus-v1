<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HealthRecord\Application\ReportShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Doctor Brief endpoint (DB-003, DB-004).
 *
 * Deliberately unauthenticated: the 256-bit token is the capability, so the
 * clinic needs no account. Only the random token appears in the URL; the
 * payload is the response body and is never cached or leaked through the
 * Referer header.
 */
final class BriefController extends Controller
{
    public function __construct(private readonly ReportShareService $shares) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $payload = $this->shares->access($token, $request->ip());

        return response()->json(
            $payload,
            200,
            [
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
