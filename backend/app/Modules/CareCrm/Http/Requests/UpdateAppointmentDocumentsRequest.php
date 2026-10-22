<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

/**
 * Either the full ordered checklist (`documents`) or a single item patch
 * (`index` + `done`), never both (APT-005).
 */
class UpdateAppointmentDocumentsRequest extends AppointmentRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'documents' => ['sometimes', 'array', 'max:100'],
            'documents.*.label' => ['required', 'string', 'max:255'],
            'documents.*.done' => ['required', 'boolean'],
            'index' => ['sometimes', 'integer', 'min:0'],
            'done' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasDocuments = $this->has('documents');
            $hasItemPatch = $this->has('index') || $this->has('done');

            if ($hasDocuments && $hasItemPatch) {
                $validator->errors()->add(
                    'documents',
                    'Send either the full documents array or a single index/done patch, not both.',
                );
            }

            if (! $hasDocuments && ! ($this->has('index') && $this->has('done'))) {
                $validator->errors()->add(
                    'documents',
                    'Provide the documents array or both index and done.',
                );
            }
        });
    }
}
