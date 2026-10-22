<?php

namespace App\Modules\HealthRecord\Http\Requests;

class DiscontinueMedicationPlanRequest extends HealthVaultRequest
{
    /**
     * The reason is required by `MedicationPlanRules::discontinueReason()` too;
     * it is stored encrypted-at-rest free text and never written to audit.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
