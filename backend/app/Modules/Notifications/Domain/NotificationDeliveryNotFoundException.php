<?php

namespace App\Modules\Notifications\Domain;

use App\Http\Responses\ProblemDetails;
use Illuminate\Contracts\Support\Responsable;

/**
 * A delivery that does not belong to the authenticated actor.
 *
 * Deliberately indistinguishable from "does not exist" (404), so one member
 * cannot enumerate another member's delivery ids inside the same workspace.
 */
final class NotificationDeliveryNotFoundException extends \RuntimeException implements Responsable
{
    public function __construct()
    {
        parent::__construct('The requested notification delivery is not available.');
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
