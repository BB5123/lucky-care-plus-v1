<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'role' => ['sometimes', Rule::in(['primary', 'assistant', 'verifier'])],
            'can_complete' => ['sometimes', 'boolean'],
        ];
    }
}
