<?php

namespace App\Modules\CareCrm\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * The authenticated actor may hold `care_task.*` but the domain refuses this
 * actor on this task (CT-002/CT-003/CT-004). Kept as 403 rather than 404
 * because the actor is already inside the workspace scope.
 */
final class CareTaskForbiddenException extends \RuntimeException implements Responsable
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'The actor may not perform this care-task action.');
    }

    public function toResponse($request)
    {
        return ProblemDetails::make(
            $request,
            403,
            'forbidden',
            'Forbidden',
            'ไม่ได้รับอนุญาตให้ดําเนินการนี้',
        );
    }
}
