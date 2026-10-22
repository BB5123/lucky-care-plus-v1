<?php

namespace App\Modules\HealthRecord\Http\Requests;

class CreateAllergyRequest extends HealthVaultRequest
{
    /**
     * Authorized by `allergy.update`: the catalog has no `allergy.create`.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'substance' => ['required', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:500'],
            'severity' => ['required', 'string', 'in:mild,moderate,severe'],
            'noted_at' => ['nullable', 'date'],
        ];
    }
}
