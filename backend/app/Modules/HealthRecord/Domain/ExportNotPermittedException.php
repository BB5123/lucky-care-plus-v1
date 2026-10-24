<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * The requester may not read any section of the record right now.
 *
 * E8 (`.ai/03-AUTHORIZATION.md`): an export counts only the rows the requester
 * may see. If that leaves nothing, the request fails closed instead of
 * producing an empty file that could be mistaken for a complete record.
 */
final class ExportNotPermittedException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested data export is not permitted for this requester.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            403,
            'export_not_permitted',
            'Export not permitted',
            'ไม่ได้รับอนุญาตให้ส่งออกข้อมูลนี้',
        );
    }
}
