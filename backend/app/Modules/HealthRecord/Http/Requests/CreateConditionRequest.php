<?php

namespace App\Modules\HealthRecord\Http\Requests;

class CreateConditionRequest extends HealthVaultRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:255'],
            'diagnosed_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,resolved'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
