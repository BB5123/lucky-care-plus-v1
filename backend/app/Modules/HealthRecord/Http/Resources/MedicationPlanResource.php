<?php

namespace App\Modules\HealthRecord\Http\Resources;

use App\Modules\HealthRecord\Models\MedicationPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MedicationPlan
 */
class MedicationPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'dosage' => $this->dosage,
            'form' => $this->form,
            'route' => $this->route,
            'doses_per_day' => $this->doses_per_day,
            'schedule_times' => $this->schedule_times,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'prescribed_by' => $this->prescribed_by,
            'instructions' => $this->instructions,
            'status' => $this->status,
            'discontinued_reason' => $this->discontinued_reason,
            'discontinued_at' => $this->discontinued_at?->toIso8601String(),
            'source' => $this->source,
            'recorded_by' => $this->recorded_by,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
