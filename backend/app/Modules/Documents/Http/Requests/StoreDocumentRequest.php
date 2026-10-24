<?php

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload input. The file body is validated again by `DocumentService`: the
 * FormRequest only enforces presence and the kind vocabulary. Actual MIME and
 * size checks happen from the bytes, never from this payload.
 */
class StoreDocumentRequest extends FormRequest
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
            'file' => ['required', 'file'],
            'kind' => ['required', 'string', Rule::in((array) config('documents.kinds', []))],
            'captured_at' => ['nullable', 'date'],
        ];
    }
}
