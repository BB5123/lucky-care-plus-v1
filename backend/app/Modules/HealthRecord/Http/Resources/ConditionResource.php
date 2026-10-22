<?php

namespace App\Modules\HealthRecord\Http\Resources;

use App\Modules\HealthRecord\Models\HealthCondition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HealthCondition
 */
class ConditionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'diagnosed_at' => $this->diagnosed_at?->format('Y-m-d'),
            'status' => $this->status,
            'notes' => $this->notes,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
