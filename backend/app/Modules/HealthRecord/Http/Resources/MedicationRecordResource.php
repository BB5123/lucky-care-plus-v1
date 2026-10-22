<?php

namespace App\Modules\HealthRecord\Http\Resources;

use App\Modules\HealthRecord\Models\MedicationRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reported dose event. `status = taken` means the reporter said so; it is
 * not verified clinical administration (`.ai/05 §3`).
 *
 * @mixin MedicationRecord
 */
class MedicationRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'status' => $this->status,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'note' => $this->note,
            'source' => $this->source,
            'recorded_by' => $this->recorded_by,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
