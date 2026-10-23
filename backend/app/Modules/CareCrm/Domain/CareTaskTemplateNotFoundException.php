<?php

namespace App\Modules\CareCrm\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A recurring template outside the current workspace/profile scope.
 *
 * Identical in shape to a missing task: the caller cannot distinguish "wrong
 * workspace" from "does not exist" (no enumeration).
 */
final class CareTaskTemplateNotFoundException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested care task template is not available.');
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
