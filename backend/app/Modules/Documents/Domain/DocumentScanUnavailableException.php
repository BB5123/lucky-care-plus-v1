<?php

namespace App\Modules\Documents\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A required scan could not be completed.
 *
 * Either no scanner is configured, or the configured scanner returned an
 * `error:<reason>` verdict (timeout, closed socket, protocol failure). Both are
 * the same safe outcome: the upload is refused before any bytes are written or
 * any metadata row is created. It is not a validation error, because retrying
 * with a different file would not help.
 */
final class DocumentScanUnavailableException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('Document scanning is required but no scanner is available.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            503,
            'scan_unavailable',
            'Document scanning unavailable',
            'ไม่สามารถตรวจสอบไฟล์ได้ในขณะนี้',
        );
    }
}
