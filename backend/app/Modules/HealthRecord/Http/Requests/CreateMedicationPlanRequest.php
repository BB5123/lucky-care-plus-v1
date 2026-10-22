<?php

namespace App\Modules\HealthRecord\Http\Requests;

class CreateMedicationPlanRequest extends HealthVaultRequest
{
    /**
     * Shape-only validation. Cross-field rules (dose count vs schedule length,
     * end date vs start date) live in `MedicationPlanRules` so jobs and
     * imports cannot bypass them.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:100'],
            'form' => ['required', 'string', 'in:tablet,capsule,liquid,injection,patch,inhaler,other'],
            'route' => ['nullable', 'string', 'in:oral,topical,injection,inhalation,other'],
            'doses_per_day' => ['required', 'integer', 'min:1', 'max:12'],
            'schedule_times' => ['required', 'array', 'min:1', 'max:12', 'distinct'],
            'schedule_times.*' => ['string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'prescribed_by' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'in:manual,care_task,import'],
        ];
    }
}
