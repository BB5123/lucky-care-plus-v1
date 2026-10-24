<?php

namespace App\Modules\Documents\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * The private object store refused the write or is unreachable.
 *
 * Download endpoints may still be able to serve existing objects, but an
 * upload must not report success when no object was stored.
 */
final class DocumentStorageUnavailableException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The private document store is unavailable.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            503,
            'storage_unavailable',
            'Document storage unavailable',
            'ไม่สามารถจัดเก็บไฟล์ได้ในขณะนี้',
        );
    }
}
