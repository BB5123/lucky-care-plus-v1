<?php

namespace App\Modules\Documents\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * No scanner is configured while the deployment requires one.
 *
 * This is a fail-closed outcome (F-09): the upload is refused before any bytes
 * are written and before a metadata row is created. It is not a validation
 * error, because retrying with a different file would not help.
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
