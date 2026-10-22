<?php

namespace App\Modules\HealthRecord\Http\Requests;

class TrendMeasurementsRequest extends HealthVaultRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownQueryKeys(['type', 'from', 'to']);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
