<?php

namespace App\Modules\CareCrm\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * An appointment that does not exist in the current workspace/profile.
 * Deliberately indistinguishable from "out of scope" (404) to prevent
 * enumeration across workspaces (APT-T08).
 */
final class AppointmentNotFoundException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested appointment is not available.');
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
