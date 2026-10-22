<?php

namespace App\Modules\HealthRecord\Http\Resources;

use App\Modules\HealthRecord\Models\HealthMeasurement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HealthMeasurement
 */
class MeasurementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'value' => (float) $this->value,
            'secondary_value' => $this->secondary_value === null ? null : (float) $this->secondary_value,
            'unit' => $this->unit,
            'context' => $this->context,
            'note' => $this->note,
            'source' => $this->source,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'recorded_by' => $this->recorded_by,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
