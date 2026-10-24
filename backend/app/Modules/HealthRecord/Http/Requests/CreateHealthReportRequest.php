<?php

namespace App\Modules\HealthRecord\Http\Requests;

/**
 * Optional report window. The default (last 30 days) and the 180-day ceiling
 * are applied server-side in `HealthReportService`; the request only rejects
 * malformed dates and an inverted explicit range.
 */
class CreateHealthReportRequest extends HealthVaultRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
        ];
    }
}
