<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A brief token that is unknown, revoked, expired, or belongs to a report that
 * is no longer active.
 *
 * All four cases return the same 404 body: the link must not reveal whether it
 * ever existed or why it stopped working (AP-008, DB-004).
 */
final class ReportShareNotFoundException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested brief is not available.');
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
