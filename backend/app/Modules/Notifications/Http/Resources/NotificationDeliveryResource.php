<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Delivery status DTO — metadata only.
 *
 * `PERMISSION_CATALOG.md §4.11` limits `notification.delivery.read` to
 * channel/status/attempts/timestamps/error_code. `params` is deliberately not
 * exposed: even though the dispatcher only stores translation placeholders, a
 * caller must not be able to turn the ledger into a message store.
 *
 * @mixin NotificationDelivery
 */
class NotificationDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'template_key' => $this->template_key,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
