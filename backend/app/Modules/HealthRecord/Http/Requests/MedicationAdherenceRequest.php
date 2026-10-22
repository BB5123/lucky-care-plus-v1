<?php

namespace App\Modules\HealthRecord\Http\Requests;

class MedicationAdherenceRequest extends HealthVaultRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownQueryKeys(['days']);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
