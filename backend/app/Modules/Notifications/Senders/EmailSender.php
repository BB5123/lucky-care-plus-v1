<?php

namespace App\Modules\Notifications\Senders;

use App\Models\User;
use App\Modules\Notifications\Contracts\ChannelSender;
use App\Modules\Notifications\Contracts\DeliveryResult;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Retention\Mail\RetentionNoticeMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email adapter for the legal retention notices (P3).
 *
 * Email is not a general notification channel yet: only
 * `retention.notice_30|7|1` are rendered here, and any other template fails
 * closed with `not_configured` instead of sending an empty message. The draft
 * is a `RetentionNoticeMail`, which carries no health data and no name.
 *
 * A missing address or a transport failure marks the delivery `failed` with a
 * machine code and lets the other channels proceed: the in-app copy is still
 * delivered, and the notice remains visible in the ledger.
 */
final class EmailSender implements ChannelSender
{
    public function channel(): string
    {
        return NotificationDelivery::CHANNEL_EMAIL;
    }

    public function send(NotificationDelivery $delivery): DeliveryResult
    {
        if (! str_starts_with($delivery->template_key, 'retention.notice_')) {
            return $this->fail($delivery, NotificationDelivery::ERROR_NOT_CONFIGURED);
        }

        $recipient = User::query()->find($delivery->recipient_user_id);
        $address = $recipient?->email;

        if (! is_string($address) || trim($address) === '') {
            return $this->fail($delivery, 'recipient_email_missing');
        }

        try {
            Mail::to($address)->send(new RetentionNoticeMail($delivery->template_key));
        } catch (Throwable $exception) {
            // The address and the body are never logged; the class is enough to
            // see a transport problem in the log stream (N-02).
            Log::warning('notification.mail_transport_failed', [
                'template_key' => $delivery->template_key,
                'error' => $exception::class,
            ]);

            return $this->fail($delivery, 'email_send_failed');
        }

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_SENT,
            'sent_at' => now(),
            'error_code' => null,
        ])->save();

        return DeliveryResult::sent();
    }

    private function fail(NotificationDelivery $delivery, string $code): DeliveryResult
    {
        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_FAILED,
            'error_code' => $code,
        ])->save();

        return DeliveryResult::failed($code);
    }
}
