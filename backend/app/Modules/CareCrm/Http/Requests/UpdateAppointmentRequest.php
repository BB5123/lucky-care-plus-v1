<?php

namespace App\Modules\CareCrm\Http\Requests;

use App\Modules\CareCrm\Models\Appointment;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends AppointmentRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'provider_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'facility' => ['sometimes', 'nullable', 'string', 'max:255'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'between:5,480'],
            'status' => ['sometimes', Rule::in([
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_MISSED,
                Appointment::STATUS_RESCHEDULED,
                Appointment::STATUS_NEEDS_FOLLOWUP,
            ])],
            'transported_by_task_id' => ['sometimes', 'nullable', 'uuid'],
            'rescheduled_from_id' => ['sometimes', 'nullable', 'uuid'],
            'prepared_documents' => ['sometimes', 'array', 'max:100'],
            'prepared_documents.*.label' => ['required', 'string', 'max:255'],
            'prepared_documents.*.done' => ['required', 'boolean'],
        ];
    }
}
