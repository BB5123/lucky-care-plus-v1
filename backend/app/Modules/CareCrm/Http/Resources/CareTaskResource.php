<?php

namespace App\Modules\CareCrm\Http\Resources;

use App\Modules\CareCrm\Models\CareTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full care-task DTO for a reader who owns the profile or holds a usable
 * `tasks`/`vitals` grant (CRM-006). Never returned to a bare assignee.
 *
 * @mixin CareTask
 */
class CareTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'profile_display_name' => $this->profile?->person?->display_name,
            'tier' => $this->tier,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'verification' => $this->verification,
            'verification_required' => $this->verification_required,
            'evidence_policy' => $this->evidence_policy,
            'escalation_level' => $this->escalation_level,
            'blocked_reason' => $this->blocked_reason,
            'review_at' => $this->review_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'version' => $this->version,
            'source' => $this->source,
            'created_by' => $this->created_by,
            'assignments' => $this->whenLoaded(
                'assignments',
                fn () => TaskAssignmentResource::collection($this->assignments)->resolve($request),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
