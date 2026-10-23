<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update for a template. Scheduling changes affect future
 * materialisation only; the service never rewrites existing occurrences.
 */
class UpdateCareTaskTemplateRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:20000'],
            'tier' => ['sometimes', Rule::in(['routine', 'delegated', 'clinical'])],
            'type' => ['sometimes', Rule::in([
                'MEDICATION_SUPPORT',
                'VITAL_MEASUREMENT',
                'APPOINTMENT',
                'DOCUMENT_FOLLOW_UP',
                'DAILY_CARE',
                'FOLLOW_UP',
                'CUSTOM',
            ])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'frequency' => ['sometimes', Rule::in(['daily', 'weekly', 'monthly'])],
            'interval' => ['sometimes', 'integer', 'between:1,30'],
            'days_of_week' => ['sometimes', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'times_of_day' => ['sometimes', 'array', 'min:1', 'max:6'],
            'times_of_day.*' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['nullable', 'date'],
            'evidence_policy' => ['sometimes', Rule::in(['none', 'value', 'photo', 'both'])],
            'verification_required' => ['sometimes', 'boolean'],
            'assignee_strategy' => ['sometimes', Rule::in(['fixed', 'rotating', 'unassigned'])],
            'assigned_user_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
