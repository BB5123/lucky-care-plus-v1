<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * The export row exists but its object is no longer downloadable.
 *
 * Expired, failed and object-missing exports are deliberately one answer:
 * the capability is gone, and the caller learns nothing about why beyond
 * "request a new export".
 */
final class ExportExpiredException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested data export has expired.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            410,
            'export_expired',
            'Export expired',
            'ไฟล์ส่งออกนี้หมดอายุแล้ว กรุณาขอใหม่',
        );
    }
}
