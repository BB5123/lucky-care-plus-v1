<?php

namespace App\Modules\Notifications\Senders;

use App\Modules\Notifications\Contracts\ChannelSender;
use App\Modules\Notifications\Contracts\DeliveryResult;
use App\Modules\Notifications\Models\NotificationDelivery;

/**
 * In-app channel: the delivery row itself is the message envelope; no external
 * transport is involved. Sending therefore means "make it visible and mark it
 * sent".
 */
final class InAppSender implements ChannelSender
{
    public function channel(): string
    {
        return NotificationDelivery::CHANNEL_IN_APP;
    }

    public function send(NotificationDelivery $delivery): DeliveryResult
    {
        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_SENT,
            'sent_at' => now(),
            'error_code' => null,
        ])->save();

        return DeliveryResult::sent();
    }
}
