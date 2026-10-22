<?php

namespace App\Modules\HealthRecord\Http\Requests;

class UpdateAllergyRequest extends HealthVaultRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'substance' => ['sometimes', 'string', 'max:255'],
            'reaction' => ['sometimes', 'nullable', 'string', 'max:500'],
            'severity' => ['sometimes', 'string', 'in:mild,moderate,severe'],
            'noted_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
