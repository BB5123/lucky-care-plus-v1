<?php

namespace App\Modules\HealthRecord\Http\Requests;

class CreateMeasurementRequest extends HealthVaultRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string'],
            'value' => ['required', 'numeric'],
            'secondary_value' => ['nullable', 'numeric'],
            'context' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
            'recorded_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'in:manual,device,care_task,import'],
        ];
    }
}
