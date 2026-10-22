<?php

namespace App\Modules\HealthRecord\Http\Requests;

class ListMedicationRecordsRequest extends HealthVaultRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownQueryKeys(['plan_id', 'status', 'limit', 'cursor']);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'in:taken,skipped,missed'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
