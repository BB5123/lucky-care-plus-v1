<?php

namespace App\Modules\HealthRecord\Http\Requests;

class UpdateMedicationPlanRequest extends HealthVaultRequest
{
    /**
     * `status` is not accepted here: closing a plan is a separate transition
     * (`discontinue`) that must carry a reason.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'dosage' => ['sometimes', 'nullable', 'string', 'max:100'],
            'form' => ['sometimes', 'string', 'in:tablet,capsule,liquid,injection,patch,inhaler,other'],
            'route' => ['sometimes', 'nullable', 'string', 'in:oral,topical,injection,inhalation,other'],
            'doses_per_day' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'schedule_times' => ['sometimes', 'array', 'min:1', 'max:12', 'distinct'],
            'schedule_times.*' => ['string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'prescribed_by' => ['sometimes', 'nullable', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
