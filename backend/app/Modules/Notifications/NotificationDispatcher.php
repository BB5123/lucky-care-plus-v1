<?php

namespace App\Modules\Notifications;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\Notifications\Application\NotificationPreferenceService;
use App\Modules\Notifications\Contracts\ChannelSender;
use App\Modules\Notifications\Domain\NotificationPayloadException;
use App\Modules\Notifications\Models\CommunicationPreference;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Senders\InAppSender;
use App\Modules\Notifications\Senders\LineSender;
use App\Modules\Notifications\Senders\PushSender;
use App\Support\Database\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Fan-out point for every notification (`.ai/05-CARE-COORDINATION.md §8`).
 *
 * Responsibilities, in order:
 *  1. validate the template key against the closed catalog;
 *  2. reject any payload that is not a pure set of translation placeholders
 *     (N-02) — before a single row is written;
 *  3. read each recipient's preferences and skip disabled channels entirely
 *     (no row, no sender call);
 *  4. apply quiet hours to push/LINE only;
 *  5. create one `queued` delivery per (recipient, channel) and call the
 *     channel adapter;
 *  6. append one business audit event that carries counts and the template
 *     key but never the params.
 *
 * A failure for one recipient is contained: the loop continues, the row is
 * marked `failed` with a machine code, and the summary reports it.
 */
final class NotificationDispatcher
{
    /** @var list<string> */
    private const FORBIDDEN_PARAM_KEYS = [
        'name',
        'phone',
        'email',
        'value',
        'diagnosis',
        'note',
        'notes',
        'measurement',
        'otp',
        'pin',
    ];

    /** @var array<string, ChannelSender> */
    private array $senders;

    public function __construct(
        private readonly NotificationTemplateCatalog $templates,
        private readonly NotificationPreferenceService $preferences,
        private readonly BusinessAuditLogger $audit,
        InAppSender $inApp,
        PushSender $push,
        LineSender $line,
    ) {
        $this->senders = [
            $inApp->channel() => $inApp,
            $push->channel() => $push,
            $line->channel() => $line,
        ];
    }

    /**
     * @param  array<int, User|int>  $recipients
     * @param  array<string, mixed>  $params  translation placeholders only
     * @param  array{
     *     tenant_id?: string,
     *     workspace_id?: string,
     *     subject_type?: string|null,
     *     subject_id?: string|null,
     *     scheduled_for?: mixed,
     *     timezone?: string
     * }  $options
     * @return array{
     *     sent: int,
     *     queued: int,
     *     failed: int,
     *     skipped: int,
     *     deliveries: list<string>
     * }
     */
    public function dispatch(
        string $templateKey,
        array $recipients,
        array $params,
        array $options = [],
    ): array {
        $required = $this->templates->requiredParams($templateKey);

        $this->assertPayloadIsSafe($params, $required);

        $tenantId = $this->stringOption($options, 'tenant_id') ?? TenantContext::tenantId();
        $workspaceId = $this->stringOption($options, 'workspace_id') ?? TenantContext::workspaceId();

        // `audit_events` is workspace-scoped and mandatory for every dispatch
        // (F-11). A caller without a trusted server-side pair is a bug, not a
        // reason to silently skip the audit row.
        if ($tenantId === null || $workspaceId === null) {
            throw new InvalidArgumentException(
                'Notification dispatch requires a server-side tenant and workspace context (N-01).'
            );
        }

        $run = fn (): array => $this->deliver(
            $templateKey,
            $this->recipientIds($recipients),
            $params,
            $options,
        );

        // The dispatcher has no acting user but must read and update the rows it
        // creates (RLS makes reads owner-only). Open the explicit system window
        // used by the delivery policies for the duration of the write.
        $runWithSystemWindow = function () use ($run): array {
            $this->setSystemWindow(true);

            try {
                return $run();
            } finally {
                $this->setSystemWindow(false);
            }
        };

        return TenantContext::matches($tenantId, $workspaceId)
            ? $runWithSystemWindow()
            : TenantContext::run($tenantId, $workspaceId, $runWithSystemWindow);
    }

    /**
     * Toggle the RLS system window used by the notification delivery policies.
     *
     * Scoped to the current transaction (`set_config(..., true)`), so it cannot
     * leak into a later request on a pooled connection.
     */
    private function setSystemWindow(bool $enabled): void
    {
        DB::selectOne('select set_config(?, ?, true)', [
            'app.notification_system',
            $enabled ? 'on' : '',
        ]);
    }

    /**
     * @param  list<int>  $recipientIds
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     * @return array{sent: int, queued: int, failed: int, skipped: int, deliveries: list<string>}
     */
    private function deliver(
        string $templateKey,
        array $recipientIds,
        array $params,
        array $options,
    ): array {
        $summary = [
            'sent' => 0,
            'queued' => 0,
            'failed' => 0,
            'skipped' => 0,
            'deliveries' => [],
        ];

        $timezone = $this->stringOption($options, 'timezone') ?? (string) config('app.timezone', 'UTC');
        $scheduledFor = $options['scheduled_for'] ?? null;
        $scheduledAt = $scheduledFor === null ? null : Carbon::parse($scheduledFor);

        $tenantId = TenantContext::tenantId();
        $workspaceId = TenantContext::workspaceId();

        foreach ($recipientIds as $recipientId) {
            try {
                $preference = $this->preferences->forUser($recipientId);
            } catch (Throwable $e) {
                report($e);
                $summary['failed']++;

                continue;
            }

            foreach (NotificationDelivery::CHANNELS as $channel) {
                if (! $preference->channelEnabled($channel)) {
                    $summary['skipped']++;

                    continue;
                }

                $delivery = null;

                try {
                    $queueUntil = $this->queueUntil($channel, $preference, $scheduledAt, $timezone);

                    $delivery = NotificationDelivery::query()->create([
                        'tenant_id' => $tenantId,
                        'workspace_id' => $workspaceId,
                        'recipient_user_id' => $recipientId,
                        'channel' => $channel,
                        'template_key' => $templateKey,
                        'params' => $params,
                        'status' => NotificationDelivery::STATUS_QUEUED,
                        'attempts' => 0,
                        'scheduled_for' => $queueUntil,
                        'subject_type' => $this->stringOption($options, 'subject_type'),
                        'subject_id' => $this->stringOption($options, 'subject_id'),
                    ]);

                    $summary['deliveries'][] = $delivery->id;

                    if ($queueUntil !== null) {
                        $summary['queued']++;

                        continue;
                    }

                    $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

                    $result = $this->senders[$channel]->send($delivery);

                    if ($result->status === NotificationDelivery::STATUS_SENT) {
                        $summary['sent']++;
                    } else {
                        $summary['failed']++;
                    }
                } catch (Throwable $e) {
                    // Contain per-delivery failures: one unreachable channel
                    // must not stop the fan-out to the other recipients.
                    report($e);
                    $summary['failed']++;

                    if ($delivery instanceof NotificationDelivery) {
                        $delivery->forceFill([
                            'status' => NotificationDelivery::STATUS_FAILED,
                            'error_code' => 'sender_error',
                        ])->save();
                    }
                }
            }
        }

        $this->audit->record([
            'actor_id' => null,
            'actor_type' => 'system',
            'action' => 'notification.dispatch',
            'target_type' => $this->stringOption($options, 'subject_type') ?? 'notification',
            'target_id' => $this->uuidOption($options, 'subject_id'),
            'result' => 'allow',
            'metadata' => [
                'template_key' => $templateKey,
                'recipients' => count($recipientIds),
                'sent' => $summary['sent'],
                'queued' => $summary['queued'],
                'failed' => $summary['failed'],
                'skipped' => $summary['skipped'],
            ],
        ]);

        return $summary;
    }

    /**
     * Earliest time this delivery may go out, or null for "send now".
     *
     * In-app is never quiet-suppressed: it does not interrupt the user.
     * Push/LINE inside a quiet window are queued to the window end. An
     * explicit future `scheduled_for` is queued as-is.
     */
    private function queueUntil(
        string $channel,
        CommunicationPreference $preference,
        ?Carbon $scheduledAt,
        string $timezone,
    ): ?Carbon {
        if ($channel === NotificationDelivery::CHANNEL_IN_APP) {
            return null;
        }

        $intendedAt = $scheduledAt ?? Carbon::now();

        $quietEnd = $preference->quietHours()?->endFor($intendedAt, $timezone);

        if ($quietEnd !== null) {
            return Carbon::instance($quietEnd);
        }

        if ($scheduledAt !== null && $scheduledAt->isFuture()) {
            return $scheduledAt;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>  $required
     */
    private function assertPayloadIsSafe(array $params, array $required): void
    {
        // Reject PHI-shaped keys even if a template were misconfigured to
        // declare one. This must run before the allowed-key check so a caller
        // cannot mask a PHI key as "unknown placeholder".
        $this->assertNoPhiKeys($params);

        foreach ($params as $key => $value) {
            if (! is_string($key) || ! in_array($key, $required, true)) {
                throw new NotificationPayloadException(
                    'Notification params may only contain declared translation placeholders (N-02).'
                );
            }

            if (is_array($value) || is_object($value)) {
                throw new NotificationPayloadException(
                    'Notification param values must be scalar translation placeholders (N-02).'
                );
            }

            if (is_string($value) && mb_strlen($value) > 160) {
                throw new NotificationPayloadException(
                    'Notification param values are limited to short translation placeholders (N-02).'
                );
            }
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $params)) {
                throw new NotificationPayloadException(
                    "Notification param '{$key}' is required by this template."
                );
            }
        }
    }

    private function assertNoPhiKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_PARAM_KEYS, true)) {
                throw new NotificationPayloadException(
                    "Notification params must not contain the PHI-shaped key '{$key}' (N-02)."
                );
            }

            $this->assertNoPhiKeys($value);
        }
    }

    /**
     * @param  array<int, User|int>  $recipients
     * @return list<int>
     */
    private function recipientIds(array $recipients): array
    {
        $ids = [];

        foreach ($recipients as $recipient) {
            $id = $recipient instanceof User ? $recipient->id : (int) $recipient;

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function uuidOption(array $options, string $key): ?string
    {
        $value = $this->stringOption($options, $key);

        return $value !== null && Str::isUuid($value) ? $value : null;
    }
}
