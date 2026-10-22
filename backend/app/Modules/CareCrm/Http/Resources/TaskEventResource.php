<?php

namespace App\Modules\CareCrm\Http\Resources;

use App\Modules\CareCrm\Models\TaskEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskEvent
 */
class TaskEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'actor_type' => $this->actor_type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'event' => $this->event,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
