<?php

namespace App\Modules\Authz;

/**
 * Coarse role gate from docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md.
 *
 * This answers one question only: may a role ever hold a permission/action,
 * before workspace membership, ownership, grant, consent, and scope checks?
 * It is a necessary condition, never sufficient: the Authorizer pipeline
 * still applies every later check.
 *
 * `C`, `C*`, `C**`, `A*`, and `A/C` cells are deliberately `true` here: the
 * document says the role may hold the action when the stated condition is
 * met, and that condition is enforced by the grant/consent/scope/domain
 * checks that run after this gate. Break-glass (ADR-002) and health-record
 * deletion (ABD-011) are hard-denied.
 */
final class RoleGate
{
    /** Deletion is denied by default for health records (ABD-011). */
    private const DELETION_PERMISSIONS = [
        'health_measurement.delete',
        'medical_document.delete',
    ];

    /** Read-only subset a viewer may ever hold (Matrix A–E). */
    private const VIEWER_READ_PERMISSIONS = [
        'workspace.read',
        // NOTE: `workspace.member.read` is deliberately NOT here.
        // docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md Matrix A marks
        // "Read member list" as D (deny) for Viewer and Caregiver; only
        // Owner, Guardian, Workspace Admin and Platform roles may read it.
        'person.read',
        'health_profile.read',
        'health_measurement.read',
        'health_condition.read',
        'allergy.read',
        'medication_plan.read',
        'medication_record.read',
        'appointment.read',
        'medical_document.read',
        'care_plan.read',
        'care_task.read',
        'care_task.evidence.read',
        'timeline.read',
        'notification.preference.read',
        'notification.delivery.read',
    ];

    /** Health/care domains that require a relationship or grant. */
    private const HEALTH_PERMISSION_PREFIXES = [
        'person.',
        'health_profile.',
        'health_condition.',
        'allergy.',
        'health_measurement.',
        'medication_plan.',
        'medication_record.',
        'appointment.',
        'care_plan.',
        'care_task.',
        'care_relationship.',
        'medical_document.',
        'timeline.',
        'ai.',
        'integration.',
        'health_report.',
        'data_export.',
    ];

    /**
     * Matrix B marks "Create profile" as deny-by-default for caregivers. A
     * healthcare professional is conditionally allowed the same action.
     */
    private const PROFILE_CREATION_PERMISSIONS = [
        'person.create',
        'health_profile.create',
    ];

    /**
     * true when the role may hold the permission at all (before
     * grant/consent/scope checks).
     */
    public function permits(string $role, string $permission, string $action): bool
    {
        // ABD-011 / ABD-012: deletion and break-glass are disabled in the MVP.
        if (in_array($permission, self::DELETION_PERMISSIONS, true)) {
            return false;
        }

        if (str_starts_with($permission, 'break_glass.')) {
            return false;
        }

        // CR-003 / baseline v1.2.0: routine definitions and escalation policy
        // are their own authority, not an implication of task-read or
        // workspace-settings authority (ABD-017).
        if (str_starts_with($permission, 'care_task.template.')) {
            return $this->taskTemplateAllows($role, $permission);
        }

        if (str_starts_with($permission, 'escalation_policy.')) {
            return $this->escalationPolicyAllows($role, $permission);
        }

        return match ($role) {
            'profile_owner' => $this->ownerAllows($permission, $action),
            'legal_guardian' => $this->guardianAllows($permission, $action),
            'caregiver' => $this->caregiverAllows($permission, $action),
            'healthcare_professional' => $this->healthcareProfessionalAllows($permission, $action),
            'workspace_admin' => $this->workspaceAdminAllows($permission, $action),
            'viewer' => $this->viewerAllows($permission, $action),
            'platform_support' => $this->platformSupportAllows($permission, $action),
            'platform_admin' => $this->platformAdminAllows($permission, $action),
            default => false,
        };
    }

    private function ownerAllows(string $permission, string $action): bool
    {
        if ($this->isNotificationPermission($permission)) {
            return true;
        }

        if (in_array($permission, [
            'workspace.read',
            'workspace.update',
            'workspace.member.read',
            'workspace.member.invite',
            'workspace.member.suspend',
            'workspace.member.remove',
            'workspace.invitation.accept',
            'audit_event.read',
            'audit_event.read_own',
        ], true)) {
            return true;
        }

        if (
            str_starts_with($permission, 'workspace.')
            || str_starts_with($permission, 'audit_event.')
            || str_starts_with($permission, 'platform.')
            || str_starts_with($permission, 'security_')
            || $permission === 'legal_authority.verify'
        ) {
            return false;
        }

        return $this->isHealthPermission($permission)
            || str_starts_with($permission, 'access_grant.')
            || str_starts_with($permission, 'consent.');
    }

    private function guardianAllows(string $permission, string $action): bool
    {
        if ($this->isNotificationPermission($permission)) {
            return true;
        }

        if (in_array($permission, [
            'workspace.read',
            'workspace.update',
            'workspace.member.read',
            'workspace.member.invite',
            'workspace.member.update_role',
            'workspace.member.suspend',
            'workspace.member.remove',
            'workspace.invitation.accept',
            'audit_event.read',
            'audit_event.read_own',
        ], true)) {
            return true;
        }

        if (
            str_starts_with($permission, 'workspace.')
            || str_starts_with($permission, 'audit_event.')
            || str_starts_with($permission, 'platform.')
            || str_starts_with($permission, 'security_')
            || $permission === 'legal_authority.verify'
        ) {
            return false;
        }

        return $this->isHealthPermission($permission)
            || str_starts_with($permission, 'access_grant.')
            || str_starts_with($permission, 'consent.');
    }

    private function caregiverAllows(string $permission, string $action): bool
    {
        if ($this->isNotificationPermission($permission)) {
            return true;
        }

        if (in_array($permission, ['workspace.read', 'workspace.invitation.accept'], true)) {
            return true;
        }

        if (
            str_starts_with($permission, 'workspace.')
            || str_starts_with($permission, 'access_grant.')
            || str_starts_with($permission, 'consent.')
            || str_starts_with($permission, 'audit_event.')
            || str_starts_with($permission, 'platform.')
            || str_starts_with($permission, 'security_')
            || $permission === 'legal_authority.verify'
        ) {
            return false;
        }

        // Matrix B "Create profile" is D for caregiver.
        if (in_array($permission, self::PROFILE_CREATION_PERMISSIONS, true)) {
            return false;
        }

        return $this->isHealthPermission($permission);
    }

    private function healthcareProfessionalAllows(string $permission, string $action): bool
    {
        // Matrix B "Create profile" is C for healthcare_professional, while it
        // is D for caregiver. Every other Matrix B cell is shared.
        if (in_array($permission, self::PROFILE_CREATION_PERMISSIONS, true)) {
            return true;
        }

        return $this->caregiverAllows($permission, $action);
    }

    private function workspaceAdminAllows(string $permission, string $action): bool
    {
        if ($this->isNotificationPermission($permission)) {
            return true;
        }

        if ($permission === 'workspace.invitation.accept') {
            return true;
        }

        // Matrix C gives Workspace Admin C* for "Read task evidence": the
        // action is allowed only when workspace policy delegates it and the
        // actor is authorized for the target profile/task. The scope/grant
        // check after this gate enforces that condition. Evidence *creation*
        // has no matrix row, so it stays deny-by-default.
        if ($permission === 'care_task.evidence.create') {
            return false;
        }

        return str_starts_with($permission, 'workspace.')
            || str_starts_with($permission, 'care_task.');
    }

    private function viewerAllows(string $permission, string $action): bool
    {
        if ($permission === 'workspace.invitation.accept') {
            return true;
        }

        // Matrix E "Update own notification preferences" is A for Viewer.
        if ($permission === 'notification.preference.update') {
            return true;
        }

        return $action === 'read'
            && in_array($permission, self::VIEWER_READ_PERMISSIONS, true);
    }

    private function platformSupportAllows(string $permission, string $action): bool
    {
        return in_array($permission, [
            // Matrix A: "Read member list" is C; "Accept own invitation" is N/A.
            'workspace.member.read',
            'workspace.read',
            'platform.user.support',
            // Matrix D C* operational workflows (still require a grant/scope).
            'consent.read',
            'consent.record',
            'consent.withdraw',
            'access_grant.revoke',
            'audit_event.read',
        ], true) || $this->isNotificationPermission($permission);
    }

    private function platformAdminAllows(string $permission, string $action): bool
    {
        if ($this->isNotificationPermission($permission)) {
            return true;
        }

        // Matrix A marks "Accept own invitation" as N/A for platform roles;
        // a platform role must not stand in for ordinary workspace membership.
        if ($permission === 'workspace.invitation.accept') {
            return false;
        }

        // Matrix D C* operational workflows (still require a grant/scope).
        if (in_array($permission, [
            'consent.read',
            'consent.record',
            'consent.withdraw',
            'access_grant.revoke',
            'audit_event.read',
        ], true)) {
            return true;
        }

        return str_starts_with($permission, 'platform.')
            || str_starts_with($permission, 'workspace.');
    }

    /**
     * Matrix C (v1.2.0): reading a routine is broad within the family, while
     * defining one is `C` for a caregiver because it changes what other people
     * are expected to do. Deactivation is admin/owner authority.
     */
    private function taskTemplateAllows(string $role, string $permission): bool
    {
        $action = substr($permission, strlen('care_task.template.'));

        return match ($role) {
            'profile_owner', 'legal_guardian', 'workspace_admin' => in_array(
                $action,
                ['read', 'create', 'update', 'deactivate'],
                true,
            ),
            'caregiver' => in_array($action, ['read', 'create', 'update'], true),
            default => false,
        };
    }

    /**
     * Matrix C (v1.2.0): reading the escalation ladder is available to the
     * family and a caregiver who follows it; editing it is owner/admin only
     * because it governs accountability rather than daily work.
     */
    private function escalationPolicyAllows(string $role, string $permission): bool
    {
        return match ($role) {
            'profile_owner', 'workspace_admin' => true,
            'legal_guardian', 'caregiver' => $permission === 'escalation_policy.read',
            default => false,
        };
    }

    private function isNotificationPermission(string $permission): bool
    {
        return str_starts_with($permission, 'notification.');
    }

    private function isHealthPermission(string $permission): bool
    {
        foreach (self::HEALTH_PERMISSION_PREFIXES as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
