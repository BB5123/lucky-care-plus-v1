<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape for a new care task. `profile_id` is checked against the
 * workspace by the service, never trusted as a scope declaration.
 */
class CreateCareTaskRequest extends FormRequest
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
            'profile_id' => ['required', 'uuid'],
            'tier' => ['required', Rule::in(['routine', 'delegated', 'clinical'])],
            'type' => ['required', Rule::in([
                'MEDICATION_SUPPORT',
                'VITAL_MEASUREMENT',
                'APPOINTMENT',
                'DOCUMENT_FOLLOW_UP',
                'DAILY_CARE',
                'FOLLOW_UP',
                'CUSTOM',
            ])],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'scheduled_for' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'evidence_policy' => ['sometimes', Rule::in(['none', 'value', 'photo', 'both'])],
            'verification_required' => ['sometimes', 'boolean'],
            'assignee_user_id' => ['nullable', 'integer'],
            'assignee_can_complete' => ['sometimes', 'boolean'],
            'template_id' => ['nullable', 'uuid'],
        ];
    }
}
