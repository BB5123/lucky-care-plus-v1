<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A second export request inside the 24-hour window.
 *
 * This is a privacy control: repeatedly downloading the same record is an
 * exfiltration signal, not merely impolite traffic. The response carries the
 * remaining window in both the body (`retry_after_seconds`) and the standard
 * `Retry-After` header.
 */
final class ExportRateLimitedException extends \RuntimeException implements Responsable
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('A data export was already requested for this profile recently.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            429,
            'export_rate_limited',
            'Export rate limited',
            'มีการขอส่งออกข้อมูลบ่อยเกินไป กรุณาลองใหม่ภายหลัง',
            ['Retry-After' => (string) $this->retryAfterSeconds],
            extra: ['retry_after_seconds' => $this->retryAfterSeconds],
        );
    }
}
