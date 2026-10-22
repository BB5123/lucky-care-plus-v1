<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A profile-scoped health record that is not visible in the current
 * workspace. Deliberately indistinguishable from "does not exist" (404).
 */
final class HealthRecordNotFoundException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested health record is not available.');
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
