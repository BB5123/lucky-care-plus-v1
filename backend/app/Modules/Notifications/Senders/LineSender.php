<?php

namespace App\Modules\Notifications\Senders;

use App\Modules\Notifications\Contracts\ChannelSender;
use App\Modules\Notifications\Contracts\DeliveryResult;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\Log;

/**
 * LINE adapter stub (ADR-003: LINE is a notification channel, not a UI).
 *
 * No HTTP call and no LINE credential is required in P1.6. The stub marks the
 * row `failed` / `not_configured` and logs only the template key (N-02).
 */
final class LineSender implements ChannelSender
{
    public function channel(): string
    {
        return NotificationDelivery::CHANNEL_LINE;
    }

    public function send(NotificationDelivery $delivery): DeliveryResult
    {
        Log::info('notification.line.not_configured', [
            'template_key' => $delivery->template_key,
        ]);

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_FAILED,
            'error_code' => NotificationDelivery::ERROR_NOT_CONFIGURED,
        ])->save();

        return DeliveryResult::failed(NotificationDelivery::ERROR_NOT_CONFIGURED);
    }
}
