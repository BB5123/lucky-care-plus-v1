<?php

namespace App\Modules\Notifications\Application;

use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\WorkspaceMembership;

/**
 * Resolves the users a domain event must notify.
 *
 * Resolution is deliberately conservative: when a recipient cannot be
 * identified (no linked user, no active primary assignment, membership no
 * longer active) the caller skips them. There is **no** fallback to "every
 * workspace member" — that would turn a missing link into a data leak.
 */
final class NotificationRecipientResolver
{
    /**
     * A user is a recipient only while their workspace membership is active.
     */
    public function activeMember(int $userId, string $tenantId, string $workspaceId): ?int
    {
        $isMember = WorkspaceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();

        return $isMember ? $userId : null;
    }

    /**
     * The user linked to the person behind a health profile.
     */
    public function profileOwnerUserId(
        string $profileId,
        string $tenantId,
        string $workspaceId,
    ): ?int {
        $profile = HealthProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('workspace_id', $workspaceId)
            ->with('person')
            ->whereKey($profileId)
            ->first();

        $userId = $profile?->person?->user_id;

        if (! is_int($userId) || $userId <= 0) {
            return null;
        }

        return $this->activeMember($userId, $tenantId, $workspaceId);
    }

    /**
     * The active primary assignee of a care task.
     */
    public function taskPrimaryAssigneeUserId(
        string $taskId,
        string $tenantId,
        string $workspaceId,
    ): ?int {
        $userId = TaskAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('workspace_id', $workspaceId)
            ->where('task_id', $taskId)
            ->where('role', TaskAssignment::ROLE_PRIMARY)
            ->whereNull('released_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('user_id');

        if ($userId === null) {
            return null;
        }

        return $this->activeMember((int) $userId, $tenantId, $workspaceId);
    }
}
