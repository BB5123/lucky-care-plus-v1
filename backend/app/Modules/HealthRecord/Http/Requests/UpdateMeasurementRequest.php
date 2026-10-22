<?php

namespace App\Modules\HealthRecord\Http\Requests;

class UpdateMeasurementRequest extends HealthVaultRequest
{
    /**
     * The type, unit and source are immutable after creation: a correction
     * changes the reading, not what kind of reading it was.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'value' => ['sometimes', 'numeric'],
            'secondary_value' => ['sometimes', 'nullable', 'numeric'],
            'context' => ['sometimes', 'nullable', 'string'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'recorded_at' => ['sometimes', 'date'],
        ];
    }
}
