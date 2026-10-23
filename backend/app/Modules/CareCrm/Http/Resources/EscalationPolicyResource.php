<?php

namespace App\Modules\CareCrm\Http\Resources;

use App\Modules\CareCrm\Models\EscalationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Escalation policy DTO. Contains no health data and no user ids: the ladder is
 * a workspace setting expressed in roles.
 *
 * @mixin EscalationPolicy
 */
class EscalationPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level,
            'after_minutes' => $this->after_minutes,
            'notify_assignee' => $this->notify_assignee,
            'notify_roles' => array_values($this->notify_roles ?? []),
            'action' => $this->action,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
