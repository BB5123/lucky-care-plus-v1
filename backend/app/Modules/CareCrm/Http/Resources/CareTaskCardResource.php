<?php

namespace App\Modules\CareCrm\Http\Resources;

use App\Modules\CareCrm\Models\CareTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Need-to-know card (AS-004 / CRM-006).
 *
 * Deliberately limited to the six safe fields plus the profile display name
 * and the optimistic-locking `version`. It never includes `description`,
 * evidence, free-text reasons, or any health value, even though the reader may
 * hold `care_task.read`. `version` is transport metadata, not health data, and
 * is required so a grant-less assignee can send `If-Match` on the next step.
 *
 * @mixin CareTask
 */
class CareTaskCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'due_at' => $this->due_at?->toIso8601String(),
            'status' => $this->status,
            'priority' => $this->priority,
            'profile_display_name' => $this->profile?->person?->display_name,
            'version' => $this->version,
        ];
    }
}
