<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Evidence input. Cross-field rules mirror what `assertRequiredEvidence()`
 * later checks at completion time: a value needs its metric, a photo needs a
 * document, and a free-text note needs its text.
 */
class CreateTaskEvidenceRequest extends FormRequest
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
            'type' => ['required', Rule::in(['value', 'photo', 'note'])],
            'metric' => ['nullable', 'string', 'max:100'],
            'value' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
            'document_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');

            if ($type === 'value') {
                if ($this->input('metric') === null || $this->input('value') === null) {
                    $validator->errors()->add('metric', 'Value evidence requires metric and value.');
                }
            }

            if ($type === 'photo' && $this->input('document_id') === null) {
                $validator->errors()->add('document_id', 'Photo evidence requires document_id.');
            }

            if ($type === 'note' && trim((string) $this->input('note')) === '') {
                $validator->errors()->add('note', 'Note evidence requires note text.');
            }
        });
    }
}
