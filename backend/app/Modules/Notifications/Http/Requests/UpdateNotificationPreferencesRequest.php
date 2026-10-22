<?php

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PATCH `/api/v1/me/notification-preferences`.
 *
 * Accepts partial updates of `channels`, `quiet_hours`, `locale`, and `digest`.
 * Two guards matter for the baseline:
 *  - unknown channel keys are rejected with 422 (no silent typo channels);
 *  - any field whose name starts with `consent` is rejected with 422 and is
 *    never forwarded to the service, because changing preferences is not a
 *    consent action (ABD-016).
 */
final class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $hasQuietHours = is_array($this->input('quiet_hours'));

        return [
            'locale' => ['sometimes', 'string', Rule::in(['th', 'en'])],
            'digest' => ['sometimes', 'string', Rule::in(['immediate', 'daily'])],
            'channels' => ['sometimes', 'array:in_app,push,line'],
            'channels.in_app' => ['sometimes', 'boolean'],
            'channels.push' => ['sometimes', 'boolean'],
            'channels.line' => ['sometimes', 'boolean'],
            'quiet_hours' => ['sometimes', 'nullable', 'array:start,end'],
            'quiet_hours.start' => [Rule::requiredIf($hasQuietHours), 'date_format:H:i'],
            'quiet_hours.end' => [Rule::requiredIf($hasQuietHours), 'date_format:H:i'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (array_keys($this->all()) as $field) {
            if (is_string($field) && str_starts_with(strtolower($field), 'consent')) {
                throw ValidationException::withMessages([
                    $field => [
                        'Notification preferences are not consent state and cannot change consent records (ABD-016).',
                    ],
                ]);
            }
        }
    }
}
