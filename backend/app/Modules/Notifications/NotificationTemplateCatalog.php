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
 */
final class NotificationTemplateCatalog
{
    /**
     * @var array<string, list<string>>
     */
    private const TEMPLATES = [
        'task.assigned' => ['tier', 'due_at'],
        'task.due_soon' => ['due_at'],
        'task.escalated' => ['escalation_level'],
        'appt.reminder' => ['scheduled_at'],
        'appt.confirmed' => ['scheduled_at'],
        'bg.activated' => ['activated_at'],
        'medication.due' => ['due_at'],
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

        return self::TEMPLATES[$templateKey];
    }
}
