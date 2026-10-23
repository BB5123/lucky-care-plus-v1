<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A report that does not exist in the current workspace/profile.
 *
 * Deliberately indistinguishable from an out-of-scope resource (404); a caller
 * must not learn whether the id exists somewhere else (AP-008).
 */
final class HealthReportNotFoundException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested health report is not available.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            404,
            'out_of_scope',
            'Resource not accessible',
            'ไม่พบข้อมูลที่ร้องขอ',
        );
    }
}
