<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * The private export store refused a read or a signed-URL request.
 *
 * A failed export is never reported as ready, and the link endpoint must not
 * return a URL that cannot be served.
 */
final class DataExportStorageUnavailableException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The private export store is unavailable.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            503,
            'storage_unavailable',
            'Export storage unavailable',
            'ไม่สามารถเข้าถึงไฟล์ส่งออกได้ในขณะนี้',
        );
    }
}
