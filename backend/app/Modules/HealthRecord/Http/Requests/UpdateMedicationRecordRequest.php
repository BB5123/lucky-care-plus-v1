<?php

namespace App\Modules\HealthRecord\Http\Requests;

/**
 * A correction of a reported dose event. `plan_id` is immutable: the event
 * belongs to the plan it was reported against.
 */
class UpdateMedicationRecordRequest extends HealthVaultRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:taken,skipped,missed'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'recorded_at' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'source' => ['sometimes', 'string', 'in:manual,care_task,import,device'],
        ];
    }
}
