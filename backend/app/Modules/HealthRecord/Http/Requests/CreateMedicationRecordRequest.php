<?php

namespace App\Modules\HealthRecord\Http\Requests;

class CreateMedicationRecordRequest extends HealthVaultRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'uuid'],
            'status' => ['required', 'string', 'in:taken,skipped,missed'],
            'scheduled_for' => ['nullable', 'date'],
            'recorded_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'in:manual,care_task,import,device'],
        ];
    }
}
