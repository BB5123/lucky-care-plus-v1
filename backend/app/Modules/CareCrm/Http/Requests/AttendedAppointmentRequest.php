<?php

namespace App\Modules\CareCrm\Http\Requests;

class AttendedAppointmentRequest extends AppointmentRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'attended_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
