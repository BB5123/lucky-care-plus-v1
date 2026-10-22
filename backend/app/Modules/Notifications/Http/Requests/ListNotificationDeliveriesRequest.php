<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query filters for the actor's own delivery ledger.
 */
final class ListNotificationDeliveriesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(NotificationDelivery::STATUSES)],
            'channel' => ['sometimes', 'string', Rule::in(NotificationDelivery::CHANNELS)],
            'unread' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
