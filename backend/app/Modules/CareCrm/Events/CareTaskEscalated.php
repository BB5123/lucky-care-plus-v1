<?php

namespace App\Modules\CareCrm\Events;

use Carbon\CarbonImmutable;

/**
 * A care task climbed to a new escalation level.
 *
 * The payload carries ids, a level and a timestamp only: never a title,
 * description, evidence value or person name. Subscribers (Notifications)
 * resolve recipients from the policy flags below under their own context
 * (N-02, ES-002).
 *
 * Published inside the escalation transaction; the notification job it
 * triggers is queued on the same connection, so a rollback removes the job
 * together with the event record.
 */
final readonly class CareTaskEscalated
{
    /**
     * @param  list<string>  $notifyRoles  membership roles from the target policy
     */
    public function __construct(
        public string $taskId,
        public string $tenantId,
        public string $workspaceId,
        public string $profileId,
        public int $escalationLevel,
        public bool $notifyAssignee,
        public array $notifyRoles,
        public ?CarbonImmutable $dueAt,
    ) {}
}
