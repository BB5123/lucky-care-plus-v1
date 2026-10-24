<?php

namespace App\Modules\Documents\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A document that is missing, soft-deleted, or outside the current profile.
 * The three cases are deliberately indistinguishable (404) so a caller cannot
 * enumerate documents in another profile or workspace (AP-008).
 */
final class DocumentNotFoundException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested document is not available.');
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
