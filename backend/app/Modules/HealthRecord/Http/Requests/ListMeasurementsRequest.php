<?php

namespace App\Modules\HealthRecord\Http\Requests;

class ListMeasurementsRequest extends HealthVaultRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownQueryKeys(['type', 'from', 'to', 'limit', 'cursor']);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
