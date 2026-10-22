<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BlockTaskRequest extends FormRequest
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
            'blocked_reason' => ['required', 'string', 'max:1000'],
            // CT-005: blocking is a temporary state with a review checkpoint.
            'review_at' => ['required', 'date'],
        ];
    }
}
