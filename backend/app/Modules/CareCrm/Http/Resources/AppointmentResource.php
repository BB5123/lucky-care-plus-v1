<?php

namespace App\Modules\CareCrm\Http\Resources;

use App\Modules\CareCrm\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full appointment DTO.
 *
 * The caller already passed `appointment.read` for this profile. `purpose` and
 * `location` are returned decrypted from the model's encrypted cast; they are
 * never copied into audit metadata or logs (N-02).
 *
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
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
            'title' => $this->title,
            'provider_name' => $this->provider_name,
            'facility' => $this->facility,
            'purpose' => $this->purpose,
            'location' => $this->location,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status,
            'cancelled_reason' => $this->cancelled_reason,
            'rescheduled_from_id' => $this->rescheduled_from_id,
            'transported_by_task_id' => $this->transported_by_task_id,
            // The API contract is ordered; `array_values` keeps a JSON array
            // even if a client once stored sparse keys.
            'prepared_documents' => array_values($this->prepared_documents ?? []),
            'follow_up_task_id' => $this->follow_up_task_id,
            'version' => $this->version,
            'source' => $this->source,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
