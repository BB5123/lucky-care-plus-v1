<?php

namespace App\Modules\CareCrm\Http\Resources;

use App\Modules\CareCrm\Models\TaskEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskEvidence
 */
class TaskEvidenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'metric' => $this->metric,
            'value' => $this->value === null ? null : (float) $this->value,
            'unit' => $this->unit,
            'document_id' => $this->document_id,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
