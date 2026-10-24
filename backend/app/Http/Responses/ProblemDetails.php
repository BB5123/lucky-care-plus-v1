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
     * @param  array<string, list<string>>  $errors  field => messages, for 422 responses
     * @param  array<string, mixed>  $extra  additional problem members (e.g. a retry window)
     */
    public static function make(
        Request $request,
        int $status,
        string $code,
        string $title,
        string $detail,
        array $headers = [],
        array $errors = [],
        array $extra = [],
    ): JsonResponse {
        $body = [
            'type' => 'https://api.luckycare.example/problems/'.str_replace('_', '-', $code),
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'trace_id' => self::traceId($request),
        ];

        // Per-field messages let a client bind the error to the right control
        // instead of showing one generic sentence. Validation messages are
        // client input, never health data, so they are safe to echo.
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        // Endpoint-specific members (for example `retry_after_seconds`) are
        // additive and never replace the standard problem fields.
        if ($extra !== []) {
            $body = array_merge($body, $extra);
        }

        return response()->json(
            $body,
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
