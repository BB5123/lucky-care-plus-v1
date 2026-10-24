<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape for a recurring care-task template.
 *
 * Authorization is declared on the route (`care_task.template.create`); this
 * request only validates shape. Tenant/workspace/profile identity come from
 * the trusted route and the Authorizer, never from the body (N-01).
 */
class CreateCareTaskTemplateRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:20000'],
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
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'interval' => ['sometimes', 'integer', 'between:1,30'],
            'days_of_week' => ['required_if:frequency,weekly', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'times_of_day' => ['required', 'array', 'min:1', 'max:6'],
            'times_of_day.*' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'evidence_policy' => ['sometimes', Rule::in(['none', 'value', 'photo', 'both'])],
            'verification_required' => ['sometimes', 'boolean'],
            'assignee_strategy' => ['sometimes', Rule::in(['fixed', 'rotating', 'unassigned'])],
            'assigned_user_id' => ['nullable', 'integer', 'required_if:assignee_strategy,fixed'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
