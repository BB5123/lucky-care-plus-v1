<?php

namespace App\Modules\CareCrm\Events;

use Carbon\CarbonImmutable;

/**
 * A primary care-task assignment was created (initial assignment or a
 * reassignment after the previous primary was released).
 *
 * The payload deliberately carries ids and timestamps only: no title, no
 * description, no evidence value. Subscribers (Notifications, Audit) must
 * resolve everything else themselves under their own authorization/context
 * (N-02, CRM-015).
 *
 * Published inside the same transaction as the assignment; the notification
 * job it triggers is queued on the same database connection, so a rollback
 * removes the queued job together with the assignment (CRM-017).
 */
final readonly class CareTaskAssigned
{
    public function __construct(
        public string $taskId,
        public string $tenantId,
        public string $workspaceId,
        public string $profileId,
        public int $assigneeUserId,
        public int $actorId,
        public string $tier,
        public ?CarbonImmutable $dueAt,
    ) {}
}
