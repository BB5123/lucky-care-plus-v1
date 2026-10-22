<?php

namespace App\Modules\HealthRecord\Http\Requests;

class ListConditionsRequest extends HealthVaultRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownQueryKeys(['status', 'limit', 'cursor']);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:active,resolved'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
