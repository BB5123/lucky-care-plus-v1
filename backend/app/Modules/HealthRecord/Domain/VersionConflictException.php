<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * Stale (or missing) `If-Match` version — AP-007 / RFC 9457 `version_conflict`.
 */
final class VersionConflictException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The resource was modified by another request.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            412,
            'version_conflict',
            'Version conflict',
            'ข้อมูลถูกแก้ไขโดยผู้อื่น กรุณาโหลดข้อมูลใหม่',
        );
    }
}
