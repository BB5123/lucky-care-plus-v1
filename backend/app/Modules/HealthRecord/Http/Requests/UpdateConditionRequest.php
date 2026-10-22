<?php

namespace App\Modules\HealthRecord\Http\Requests;

class UpdateConditionRequest extends HealthVaultRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'name' => ['sometimes', 'string', 'max:255'],
            'diagnosed_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:active,resolved'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
