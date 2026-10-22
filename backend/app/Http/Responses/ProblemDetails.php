<?php

namespace App\Http\Responses;

use App\Modules\Authz\Decision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * RFC 9457 `application/problem+json` responses.
 *
 * Details are deliberately generic: a denial must not reveal whether a
 * resource exists or disclose authorization internals (F-09, N-02).
 */
final class ProblemDetails
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public static function make(
        Request $request,
        int $status,
        string $code,
        string $title,
        string $detail,
        array $headers = [],
    ): JsonResponse {
        return response()->json(
            [
                'type' => 'https://api.luckycare.example/problems/'.str_replace('_', '-', $code),
                'title' => $title,
                'status' => $status,
                'code' => $code,
                'detail' => $detail,
                'trace_id' => self::traceId($request),
            ],
            $status,
            array_merge(['Content-Type' => 'application/problem+json'], $headers),
        );
    }

    public static function fromDecision(Decision $decision, Request $request): JsonResponse
    {
        return self::make(
            $request,
            $decision->status(),
            $decision->code(),
            self::titleFor($decision->code()),
            self::detailFor($decision->status()),
        );
    }

    private static function titleFor(string $code): string
    {
        return match ($code) {
            'unauthenticated' => 'Authentication required',
            'out_of_scope' => 'Resource not accessible',
            'forbidden' => 'Forbidden',
            'no_consent' => 'Consent required',
            'domain_rule' => 'Domain rule violation',
            'unknown_permission' => 'Authorization unavailable',
            'authorization_unavailable' => 'Authorization unavailable',
            default => 'Request failed',
        };
    }

    private static function detailFor(int $status): string
    {
        return match ($status) {
            401 => 'กรุณายืนยันตัวตนก่อนเข้าถึงข้อมูล',
            403 => 'ไม่ได้รับอนุญาตให้ดำเนินการนี้',
            404 => 'ไม่พบข้อมูลที่ร้องขอ',
            default => 'ไม่สามารถดำเนินการได้ในขณะนี้',
        };
    }

    private static function traceId(Request $request): string
    {
        $traceId = $request->header('X-Request-Id');

        return is_string($traceId) && $traceId !== '' ? $traceId : (string) Str::uuid7();
    }
}
