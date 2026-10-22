<?php

namespace App\Modules\Notifications\Senders;

use App\Modules\Notifications\Contracts\ChannelSender;
use App\Modules\Notifications\Contracts\DeliveryResult;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Push adapter stub.
 *
 * No external network call is made in P1.6. The stub exists so the dispatcher
 * can be exercised end to end: it records `failed` / `not_configured` and logs
 * **only the template key** — never params, recipient identity, or message
 * body (N-02).
 */
final class PushSender implements ChannelSender
{
    public function channel(): string
    {
        return NotificationDelivery::CHANNEL_PUSH;
    }

    public function send(NotificationDelivery $delivery): DeliveryResult
    {
        Log::info('notification.push.not_configured', [
            'template_key' => $delivery->template_key,
        ]);

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_FAILED,
            'error_code' => NotificationDelivery::ERROR_NOT_CONFIGURED,
        ])->save();

        return DeliveryResult::failed(NotificationDelivery::ERROR_NOT_CONFIGURED);
    }
}
