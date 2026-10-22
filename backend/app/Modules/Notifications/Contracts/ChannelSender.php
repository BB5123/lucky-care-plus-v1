<?php

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Models\NotificationDelivery;

/**
 * A channel adapter. Implementations own the transport and persist the
 * terminal status of the delivery they were given.
 */
interface ChannelSender
{
    /**
     * The channel this adapter handles (`in_app`, `push`, `line`).
     */
    public function channel(): string;

    public function send(NotificationDelivery $delivery): DeliveryResult;
}
