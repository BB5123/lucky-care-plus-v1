<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Domain\UnknownNotificationTemplateException;

/**
 * The code-level whitelist of notification templates.
 *
 * A template key is the only thing that may be persisted in the clear besides
 * its placeholder values. Each template declares the placeholders it accepts;
 * anything else is rejected by the dispatcher. Placeholders are deliberately
 * non-identifying (`due_at`, `tier`, …): a template can never be given a name,
 * phone number, measurement, or note (N-02).
 *
 * The list is closed on purpose. Adding a template is a code change with a
 * review, not a runtime/config action.
 *
 * `optional` placeholders are still whitelisted keys (anything outside
 * `required` + `optional` is rejected) but may be omitted by callers that
 * cannot legitimately know them. Subject ids are optional so the existing
 * template contract stays backward compatible while wiring domain events can
 * carry the id used for idempotency.
 */
final class NotificationTemplateCatalog
{
    /**
     * @var array<string, array{required: list<string>, optional: list<string>}>
     */
    private const TEMPLATES = [
        'task.assigned' => [
            'required' => ['tier', 'due_at'],
            'optional' => ['task_id'],
        ],
        'task.due_soon' => [
            'required' => ['due_at'],
            'optional' => ['task_id'],
        ],
        'task.escalated' => [
            'required' => ['escalation_level'],
            'optional' => [],
        ],
        'appt.reminder' => [
            'required' => ['scheduled_at'],
            'optional' => ['appointment_id'],
        ],
        'appt.confirmed' => [
            'required' => ['scheduled_at'],
            'optional' => [],
        ],
        'bg.activated' => [
            'required' => ['activated_at'],
            'optional' => [],
        ],
        'medication.due' => [
            'required' => ['due_at'],
            'optional' => [],
        ],
    ];

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public function has(string $templateKey): bool
    {
        return array_key_exists($templateKey, self::TEMPLATES);
    }

    /**
     * @return list<string>
     *
     * @throws UnknownNotificationTemplateException
     */
    public function requiredParams(string $templateKey): array
    {
        if (! $this->has($templateKey)) {
            throw new UnknownNotificationTemplateException(
                "Unknown notification template key '{$templateKey}'."
            );
        }

        return self::TEMPLATES[$templateKey]['required'];
    }

    /**
     * Every key this template accepts: required placeholders first, then the
     * optional subject id. The dispatcher rejects anything outside this list.
     *
     * @return list<string>
     *
     * @throws UnknownNotificationTemplateException
     */
    public function allowedParams(string $templateKey): array
    {
        if (! $this->has($templateKey)) {
            throw new UnknownNotificationTemplateException(
                "Unknown notification template key '{$templateKey}'."
            );
        }

        return [
            ...self::TEMPLATES[$templateKey]['required'],
            ...self::TEMPLATES[$templateKey]['optional'],
        ];
    }
}
