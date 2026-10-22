<?php

namespace App\Modules\HealthRecord\Http\Requests;

class ListAllergiesRequest extends HealthVaultRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownQueryKeys(['severity', 'limit', 'cursor']);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'severity' => ['nullable', 'string', 'in:mild,moderate,severe'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
