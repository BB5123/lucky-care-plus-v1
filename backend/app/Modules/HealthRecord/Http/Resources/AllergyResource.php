<?php

namespace App\Modules\HealthRecord\Http\Resources;

use App\Modules\HealthRecord\Models\Allergy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Allergy
 */
class AllergyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'substance' => $this->substance,
            'reaction' => $this->reaction,
            'severity' => $this->severity,
            'noted_at' => $this->noted_at?->format('Y-m-d'),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
