<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Validation\Rule;

class CreateAppointmentRequest extends AppointmentRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'facility' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:20000'],
            'location' => ['nullable', 'string', 'max:20000'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'between:5,480'],
            'source' => ['sometimes', Rule::in(['manual'])],
            'transported_by_task_id' => ['nullable', 'uuid'],
            'rescheduled_from_id' => ['nullable', 'uuid'],
            'prepared_documents' => ['sometimes', 'array', 'max:100'],
            'prepared_documents.*.label' => ['required', 'string', 'max:255'],
            'prepared_documents.*.done' => ['required', 'boolean'],
        ];
    }
}
