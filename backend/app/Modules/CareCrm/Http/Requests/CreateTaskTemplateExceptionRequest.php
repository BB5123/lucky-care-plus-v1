<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One "not this occurrence" override.
 *
 * `skip` suppresses every slot of `occurrence_date`; `reschedule` needs the
 * replacement instant in `rescheduled_to`. The template's `If-Match` version
 * is read by the controller, not from this body.
 */
class CreateTaskTemplateExceptionRequest extends FormRequest
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
            'occurrence_date' => ['required', 'date'],
            'action' => ['required', Rule::in(['skip', 'reschedule'])],
            'rescheduled_to' => ['nullable', 'required_if:action,reschedule', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
