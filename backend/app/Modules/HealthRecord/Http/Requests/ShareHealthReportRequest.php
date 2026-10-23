<?php

namespace App\Modules\HealthRecord\Http\Requests;

/**
 * A share is created with a short, bounded lifetime. The service validates the
 * same range again so a non-HTTP caller cannot bypass it.
 *
 * The ceiling is 30 minutes, not 60: blueprint DB-003 (`.ai/05` §9) says a QR
 * token must live "≤30 นาที". A ticket that allows 60 would silently break that
 * bound, and the blueprint outranks a ticket.
 */
class ShareHealthReportRequest extends HealthVaultRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'expires_in_minutes' => ['sometimes', 'integer', 'between:5,30'],
            'recipient_label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
