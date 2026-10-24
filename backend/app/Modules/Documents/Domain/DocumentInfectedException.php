<?php

namespace App\Modules\Documents\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * The configured scanner classified the uploaded bytes as malware.
 *
 * The upload path scans before the object is written, so the infected bytes are
 * never persisted and no `medical_documents` row is created. The security
 * audit row written by the service contains ids and non-content metadata only
 * (no filename, no file bytes).
 */
final class DocumentInfectedException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The uploaded document was rejected by the malware scanner.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            422,
            'document_infected',
            'Document rejected',
            'ไฟล์นี้ถูกปฏิเสธเนื่องจากไม่ผ่านการตรวจสอบความปลอดภัย',
        );
    }
}
