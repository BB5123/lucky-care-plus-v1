<?php

namespace App\Modules\CareCrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * List filters. `assignee` is either the literal `me` or a user UUID.
 */
class ListCareTasksRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in([
                'scheduled',
                'open',
                'assigned',
                'accepted',
                'in_progress',
                'blocked',
                'completed',
                'cancelled',
            ])],
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
            'assignee' => ['sometimes', 'string', 'regex:/^(me|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/'],
            'due_before' => ['sometimes', 'date'],
            'overdue' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
