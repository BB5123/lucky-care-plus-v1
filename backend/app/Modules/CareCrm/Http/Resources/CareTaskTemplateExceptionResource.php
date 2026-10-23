<?php

namespace App\Modules\CareCrm\Http\Resources;

use App\Modules\CareCrm\Models\CareTaskTemplateException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareTaskTemplateException
 */
class CareTaskTemplateExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'occurrence_date' => $this->occurrence_date?->format('Y-m-d'),
            'action' => $this->action,
            'rescheduled_to' => $this->rescheduled_to?->toIso8601String(),
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
