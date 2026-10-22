<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteTaskRequest extends FormRequest
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
            'outcome' => ['sometimes', Rule::in(['done', 'skipped', 'missed', 'failed'])],
        ];
    }
}
