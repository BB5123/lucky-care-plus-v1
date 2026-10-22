<?php

namespace App\Modules\CareCrm\Http\Requests;

use App\Modules\CareCrm\Models\Appointment;
use Illuminate\Validation\Rule;

class ListAppointmentsRequest extends AppointmentRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownQueryKeys(['status', 'from', 'to', 'upcoming', 'limit', 'cursor']);

        // The contract spells the filter as `upcoming=true`; Laravel's
        // `boolean` rule accepts 1/0 but not the literal words, so normalize
        // the two documented spellings before validation.
        $upcoming = $this->query('upcoming');

        if (is_string($upcoming) && in_array(strtolower($upcoming), ['true', 'false'], true)) {
            $this->merge(['upcoming' => strtolower($upcoming) === 'true']);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([
                Appointment::STATUS_PLANNED,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_ATTENDED,
                Appointment::STATUS_RESCHEDULED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_MISSED,
                Appointment::STATUS_NEEDS_FOLLOWUP,
            ])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'upcoming' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
