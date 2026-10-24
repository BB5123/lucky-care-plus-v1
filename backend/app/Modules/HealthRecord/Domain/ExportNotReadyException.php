<?php

namespace App\Modules\HealthRecord\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * The export exists but has not finished building yet.
 *
 * `failed` is deliberately not routed here: a failed export has no object at
 * all, so the link endpoint treats it as expired (410) rather than inviting a
 * poll that can never succeed.
 */
final class ExportNotReadyException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested data export is still being built.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            409,
            'export_not_ready',
            'Export not ready',
            'ไฟล์ส่งออกยังไม่พร้อม กรุณาลองใหม่ภายหลัง',
        );
    }
}
