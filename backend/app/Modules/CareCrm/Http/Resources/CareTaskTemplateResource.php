<?php

namespace App\Modules\CareCrm\Http\Resources;

use App\Modules\CareCrm\Models\CareTaskTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Template DTO. `description` is decrypted by the model cast; callers have
 * already passed `care_task.read` for this profile.
 *
 * @mixin CareTaskTemplate
 */
class CareTaskTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'created_by' => $this->created_by,
            'title' => $this->title,
            'description' => $this->description,
            'tier' => $this->tier,
            'type' => $this->type,
            'priority' => $this->priority,
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'days_of_week' => array_values($this->days_of_week ?? []),
            'times_of_day' => array_values($this->times_of_day ?? []),
            'timezone' => $this->timezone,
            'starts_on' => $this->starts_on?->format('Y-m-d'),
            'ends_on' => $this->ends_on?->format('Y-m-d'),
            'evidence_policy' => $this->evidence_policy,
            'verification_required' => $this->verification_required,
            'assignee_strategy' => $this->assignee_strategy,
            'assigned_user_id' => $this->assigned_user_id,
            'is_active' => $this->is_active,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (array_key_exists('exceptions_count', $this->resource->getAttributes())) {
            $data['exceptions_count'] = (int) $this->resource->getAttribute('exceptions_count');
        }

        if ($this->relationLoaded('exceptions')) {
            $data['exceptions'] = CareTaskTemplateExceptionResource::collection($this->exceptions)
                ->resolve($request);
        }

        return $data;
    }
}
