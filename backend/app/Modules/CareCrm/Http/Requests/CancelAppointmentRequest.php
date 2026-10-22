<?php

namespace App\Modules\CareCrm\Http\Requests;

class CancelAppointmentRequest extends AppointmentRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
