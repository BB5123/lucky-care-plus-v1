<?php

namespace App\Modules\Retention\Services;

use Carbon\CarbonInterface;

/**
 * Read model for one workspace's inactivity state.
 *
 * `schedule` is the 30/14/7 reminder plan computed from the purge due date.
 * `notificationsDue` lists the offsets whose due date has already passed.
 */
final readonly class InactivityStatus
{
    /**
     * @param  list<array{days_before: int, due_at: CarbonInterface}>  $schedule
     * @param  list<int>  $notificationsDue
     */
    public function __construct(
        public string $tenantId,
        public string $workspaceId,
        public CarbonInterface $lastActivityAt,
        public CarbonInterface $purgeDueAt,
        public array $schedule,
        public bool $purgeDue,
        public array $notificationsDue,
    ) {}

    public function inNotificationWindow(CarbonInterface $now): bool
    {
        $firstOffset = $this->schedule[0]['days_before'] ?? 0;

        return $now->greaterThanOrEqualTo($this->purgeDueAt->copy()->subDays($firstOffset));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'last_activity_at' => $this->lastActivityAt->toIso8601String(),
            'purge_due_at' => $this->purgeDueAt->toIso8601String(),
            'purge_due' => $this->purgeDue,
            'notifications_due' => $this->notificationsDue,
            'schedule' => array_map(
                fn (array $entry): array => [
                    'days_before' => $entry['days_before'],
                    'due_at' => $entry['due_at']->toIso8601String(),
                ],
                $this->schedule,
            ),
        ];
    }
}
