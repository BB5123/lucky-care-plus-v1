<?php

namespace App\Modules\Authz;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\Consent\Services\GrantChecker;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Modules\Workspace\Services\WorkspaceResolver;
use App\Support\Database\TenantContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single authorization decision point for every enforcement path
 * (HTTP, queue job, export, AI gateway).
 *
 * Order is fixed by `.ai/03-AUTHORIZATION.md §2`:
 *   1. authentication
 *   2. permission exists
 *   3. workspace resolution + active membership
 *   4. role gate
 *   5. profile ownership / active grant
 *   6. consent for the requested purpose
 *   7. audit, then allow
 *
 * Any unexpected failure short-circuits to `authorization_unavailable` / 503.
 * The pipeline never falls through to an allow (F-09).
 */
final class Authorizer
{
    /**
     * Explicit permission -> [scope, action] table.
     *
     * A missing entry is a bug, not a client error: the pipeline denies with
     * `unknown_permission` / 500. `null` scope means the permission is
     * workspace-level (workspace membership, platform, self-service), so a
     * profile reference is validated for workspace membership only.
     *
     * @var array<string, array{scope: ?string, action: string}>
     */
    private const PERMISSION_MAP = [
        'access_grant.create' => ['scope' => 'profile.basic', 'action' => 'create'],
        'access_grant.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'access_grant.revoke' => ['scope' => 'profile.basic', 'action' => 'update'],
        'access_grant.update' => ['scope' => 'profile.basic', 'action' => 'update'],
        'ai.conversation.create' => ['scope' => 'ai_context', 'action' => 'create'],
        'ai.conversation.delete_request' => ['scope' => 'ai_context', 'action' => 'update'],
        'ai.conversation.read' => ['scope' => 'ai_context', 'action' => 'read'],
        'ai.health_context.use' => ['scope' => 'ai_context', 'action' => 'read'],
        'ai.report.create' => ['scope' => 'ai_context', 'action' => 'create'],
        'allergy.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'allergy.update' => ['scope' => 'profile.basic', 'action' => 'update'],
        'appointment.cancel' => ['scope' => 'appointments', 'action' => 'update'],
        'appointment.create' => ['scope' => 'appointments', 'action' => 'create'],
        'appointment.read' => ['scope' => 'appointments', 'action' => 'read'],
        'appointment.update' => ['scope' => 'appointments', 'action' => 'update'],
        'audit_event.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'audit_event.read_own' => ['scope' => 'profile.basic', 'action' => 'read'],
        'break_glass.approve' => ['scope' => 'profile.basic', 'action' => 'update'],
        'break_glass.request' => ['scope' => 'profile.basic', 'action' => 'create'],
        'break_glass.use' => ['scope' => 'profile.basic', 'action' => 'read'],
        'care_plan.create' => ['scope' => 'tasks', 'action' => 'create'],
        'care_plan.read' => ['scope' => 'tasks', 'action' => 'read'],
        'care_plan.update' => ['scope' => 'tasks', 'action' => 'update'],
        'care_relationship.create' => ['scope' => 'profile.basic', 'action' => 'create'],
        'care_relationship.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'care_relationship.revoke' => ['scope' => 'profile.basic', 'action' => 'update'],
        'care_relationship.update' => ['scope' => 'profile.basic', 'action' => 'update'],
        'care_task.accept' => ['scope' => 'tasks', 'action' => 'update'],
        'care_task.assign' => ['scope' => 'tasks', 'action' => 'update'],
        'care_task.block' => ['scope' => 'tasks', 'action' => 'update'],
        'care_task.cancel' => ['scope' => 'tasks', 'action' => 'update'],
        'care_task.complete' => ['scope' => 'tasks', 'action' => 'update'],
        'care_task.create' => ['scope' => 'tasks', 'action' => 'create'],
        'care_task.evidence.create' => ['scope' => 'tasks', 'action' => 'create'],
        'care_task.evidence.read' => ['scope' => 'tasks', 'action' => 'read'],
        'care_task.read' => ['scope' => 'tasks', 'action' => 'read'],
        'care_task.reopen' => ['scope' => 'tasks', 'action' => 'update'],
        'care_task.start' => ['scope' => 'tasks', 'action' => 'update'],
        'care_task.verify' => ['scope' => 'tasks', 'action' => 'update'],
        'consent.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'consent.record' => ['scope' => 'profile.basic', 'action' => 'create'],
        'consent.withdraw' => ['scope' => 'profile.basic', 'action' => 'update'],
        'data_export.cancel' => ['scope' => 'profile.basic', 'action' => 'update'],
        'data_export.download' => ['scope' => 'profile.basic', 'action' => 'export'],
        'data_export.request' => ['scope' => 'profile.basic', 'action' => 'export'],
        'health_condition.correct' => ['scope' => 'profile.basic', 'action' => 'update'],
        'health_condition.create' => ['scope' => 'profile.basic', 'action' => 'create'],
        'health_condition.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'health_measurement.correct' => ['scope' => 'vitals', 'action' => 'update'],
        'health_measurement.create' => ['scope' => 'vitals', 'action' => 'create'],
        'health_measurement.delete' => ['scope' => 'vitals', 'action' => 'update'],
        'health_measurement.read' => ['scope' => 'vitals', 'action' => 'read'],
        'health_profile.archive' => ['scope' => 'profile.basic', 'action' => 'update'],
        'health_profile.create' => ['scope' => 'profile.basic', 'action' => 'create'],
        'health_profile.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'health_profile.update' => ['scope' => 'profile.basic', 'action' => 'update'],
        'health_report.create' => ['scope' => 'profile.basic', 'action' => 'create'],
        'health_report.export' => ['scope' => 'profile.basic', 'action' => 'export'],
        'health_report.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'integration.health_data.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'integration.health_data.write' => ['scope' => 'profile.basic', 'action' => 'update'],
        'legal_authority.verify' => ['scope' => 'profile.basic', 'action' => 'update'],
        'medical_document.delete' => ['scope' => 'documents', 'action' => 'update'],
        'medical_document.export' => ['scope' => 'documents', 'action' => 'export'],
        'medical_document.read' => ['scope' => 'documents', 'action' => 'read'],
        'medical_document.share' => ['scope' => 'documents', 'action' => 'share'],
        'medical_document.upload' => ['scope' => 'documents', 'action' => 'create'],
        'medication_plan.create' => ['scope' => 'medications', 'action' => 'create'],
        'medication_plan.discontinue' => ['scope' => 'medications', 'action' => 'update'],
        'medication_plan.read' => ['scope' => 'medications', 'action' => 'read'],
        'medication_plan.update' => ['scope' => 'medications', 'action' => 'update'],
        'medication_record.correct' => ['scope' => 'medications', 'action' => 'update'],
        'medication_record.create' => ['scope' => 'medications', 'action' => 'create'],
        'medication_record.read' => ['scope' => 'medications', 'action' => 'read'],
        'notification.delivery.read' => ['scope' => null, 'action' => 'read'],
        'notification.preference.read' => ['scope' => null, 'action' => 'read'],
        'notification.preference.update' => ['scope' => null, 'action' => 'update'],
        'person.create' => ['scope' => 'profile.basic', 'action' => 'create'],
        'person.merge' => ['scope' => 'profile.basic', 'action' => 'update'],
        'person.read' => ['scope' => 'profile.basic', 'action' => 'read'],
        'person.update' => ['scope' => 'profile.basic', 'action' => 'update'],
        'platform.configuration.manage' => ['scope' => null, 'action' => 'update'],
        'platform.user.support' => ['scope' => null, 'action' => 'update'],
        'security_access_review.read' => ['scope' => null, 'action' => 'read'],
        'timeline.read' => ['scope' => 'timeline', 'action' => 'read'],
        'workspace.invitation.accept' => ['scope' => null, 'action' => 'update'],
        'workspace.member.invite' => ['scope' => null, 'action' => 'create'],
        'workspace.member.read' => ['scope' => null, 'action' => 'read'],
        'workspace.member.remove' => ['scope' => null, 'action' => 'update'],
        'workspace.member.suspend' => ['scope' => null, 'action' => 'update'],
        'workspace.member.update_role' => ['scope' => null, 'action' => 'update'],
        'workspace.read' => ['scope' => null, 'action' => 'read'],
        'workspace.update' => ['scope' => null, 'action' => 'update'],
    ];

    /**
     * Permissions whose very purpose requires consent, even when the caller
     * forgets to pass a purpose. A missing purpose therefore denies.
     *
     * @var array<string, string>
     */
    private const CONSENT_REQUIRED_PURPOSES = [
        'ai.health_context.use' => 'ai_processing',
        'ai.conversation.create' => 'ai_processing',
        'ai.report.create' => 'ai_processing',
        'data_export.request' => 'data_export',
        'data_export.download' => 'data_export',
        'health_report.export' => 'data_export',
        'medical_document.export' => 'data_export',
        'medical_document.share' => 'third_party_share',
        'integration.health_data.read' => 'third_party_share',
        'integration.health_data.write' => 'third_party_share',
    ];

    /**
     * Permissions scoped to `self_membership` (baseline v1.1.0, Matrix E).
     *
     * They are the only permissions the Authorizer accepts together with a
     * workspace-less {@see ResourceRef::forSelfMembership()} resource. The list
     * is deliberately closed: a future permission that happens to have a null
     * scope in `PERMISSION_MAP` must not silently become self-service.
     *
     * @var list<string>
     */
    private const SELF_MEMBERSHIP_PERMISSIONS = [
        'notification.preference.read',
        'notification.preference.update',
        'notification.delivery.read',
    ];

    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly GrantChecker $grants,
        private readonly SecurityAuditLogger $audit,
        private readonly WorkspaceResolver $workspaces,
        private readonly RoleGate $gate,
    ) {}

    public function authorize(
        ?User $actor,
        string $permission,
        ResourceRef $resource,
        ?string $purpose = null,
    ): Decision {
        try {
            $decision = $this->evaluate($actor, $permission, $resource, $purpose);
        } catch (Throwable $e) {
            // Fail closed. Do not audit here: the authorization stack is not
            // trustworthy, and the task requires no side effect on failure.
            report($e);

            return Decision::deny('authorization_unavailable', 503, 'dependency_unavailable');
        }

        try {
            $this->auditDecision($actor, $permission, $resource, $decision);
        } catch (Throwable $e) {
            report($e);

            // An allow is not valid without its audit row (F-11).
            if ($decision->allowed()) {
                return Decision::deny('authorization_unavailable', 503, 'audit_unavailable');
            }
        }

        return $decision;
    }

    /**
     * Authorize several `(permission, profile)` checks for one workspace in a
     * single pass.
     *
     * Aggregate read models must prove every card separately (ADR-010,
     * CRM-023), which would otherwise cost a full pipeline round trip — and a
     * hash-chained audit append — per card. This method keeps the same rules
     * as {@see authorize()} (catalog, membership, role gate, ownership/usable
     * grant, consent) and appends one audit row per decision, but shares the
     * workspace/membership/profile/grant lookups.
     *
     * The workspace is trusted server-side context (`workspace.context` has
     * already resolved it); the caller must never rebuild it from client input
     * (N-01).
     *
     * @param  list<array{permission: string, profile_id: ?string}>  $checks
     * @return list<Decision> in the same order as `$checks`
     */
    public function authorizeBatch(?User $actor, Workspace $workspace, array $checks): array
    {
        if ($checks === []) {
            return [];
        }

        try {
            return $this->withinWorkspace($workspace, function () use ($actor, $workspace, $checks): array {
                $decisions = $this->evaluateBatch($actor, $workspace, $checks);

                try {
                    $this->auditBatch($actor, $workspace, $checks, $decisions);
                } catch (Throwable $e) {
                    report($e);

                    // An allow is not valid without its audit row (F-11). A
                    // deny stays a deny even if the audit append failed.
                    foreach ($decisions as $index => $decision) {
                        if ($decision->allowed()) {
                            $decisions[$index] = Decision::deny(
                                'authorization_unavailable',
                                503,
                                'audit_unavailable',
                            );
                        }
                    }
                }

                return array_values($decisions);
            });
        } catch (Throwable $e) {
            report($e);

            return array_fill(
                0,
                count($checks),
                Decision::deny('authorization_unavailable', 503, 'dependency_unavailable'),
            );
        }
    }

    /**
     * @return array{scope: ?string, action: string}|null
     */
    public function mappingFor(string $permission): ?array
    {
        return self::PERMISSION_MAP[$permission] ?? null;
    }

    /**
     * True for the frozen `self_membership` permissions (Matrix E).
     *
     * `EnsureAuthorized` uses this to decide whether a route without a
     * `{workspace}` may still be authorized through this single entry point.
     */
    public function supportsSelfService(string $permission): bool
    {
        return in_array($permission, self::SELF_MEMBERSHIP_PERMISSIONS, true);
    }

    private function evaluate(
        ?User $actor,
        string $permission,
        ResourceRef $resource,
        ?string $purpose,
    ): Decision {
        // [1] Authentication.
        if ($actor === null) {
            return Decision::deny('unauthenticated', 401, 'not_authenticated');
        }

        // [2] The permission must exist in the frozen catalog. A missing ID
        // is a server bug, so it is a 500 and never a client-facing 403.
        if (! $this->catalog->has($permission)) {
            return Decision::deny('unknown_permission', 500, 'permission_not_in_catalog');
        }

        $mapping = $this->mappingFor($permission);

        if ($mapping === null || ! $this->catalog->allowsAction($mapping['action'])) {
            return Decision::deny('unknown_permission', 500, 'permission_not_mapped');
        }

        // [2b] Self-service (`self_membership`) decisions are about the
        // actor's own rows and have no workspace to resolve. They still go
        // through this pipeline so there is exactly one decision point.
        if ($resource->isSelfService()) {
            return $this->evaluateSelfService($actor, $permission, $resource, $mapping);
        }

        if ($resource->workspaceId === null) {
            return Decision::deny('out_of_scope', 404, 'workspace_out_of_scope');
        }

        // [3] Workspace resolution. A missing workspace and a non-member are
        // indistinguishable to the caller: both are 404 (no enumeration).
        $workspace = $this->workspaces->resolve($resource->workspaceId);

        if ($workspace === null) {
            return Decision::deny('out_of_scope', 404, 'workspace_out_of_scope');
        }

        // The rest of the pipeline must run under the resolved tenant/workspace
        // so RLS applies to memberships, profiles, grants, and consents. `run`
        // restores the previous context even when a check returns early.
        return TenantContext::run($workspace->tenant_id, $workspace->id, function () use (
            $actor,
            $permission,
            $resource,
            $purpose,
            $workspace,
            $mapping,
        ): Decision {
            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->first();

            if ($membership === null) {
                return Decision::deny('out_of_scope', 404, 'not_a_member');
            }

            // [4] Role gate. Role never replaces scope/grant checks, but a
            // role that can never hold an action (e.g. viewer + update) is a
            // hard deny before any resource lookup.
            if (! $this->gate->permits($membership->role, $permission, $mapping['action'])) {
                return Decision::deny('forbidden', 403, 'role_cannot_hold_permission');
            }

            // [5] Resource scope.
            if ($resource->profileId !== null) {
                if (! Str::isUuid($resource->profileId)) {
                    return Decision::deny('out_of_scope', 404, 'profile_out_of_scope');
                }

                $profile = HealthProfile::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($resource->profileId)
                    ->first();

                if ($profile === null) {
                    return Decision::deny('out_of_scope', 404, 'profile_out_of_scope');
                }

                // Workspace-level permissions (`scope === null`) are validated
                // for workspace membership above; they are not profile grants.
                if ($mapping['scope'] !== null) {
                    $owner = $this->isProfileOwner($actor, $profile);
                    $granted = $owner
                        ? false
                        : $this->hasUsableGrant($actor, $profile, $mapping['scope'], $mapping['action']);

                    if (! $owner && ! $granted) {
                        return Decision::deny('forbidden', 403, 'scope_not_granted');
                    }
                }
            }

            // [6] Consent. A consent-required permission without a purpose is
            // denied rather than silently treated as care coordination.
            foreach ($this->requiredConsentPurposes($permission, $purpose) as $requiredPurpose) {
                if ($resource->profileId === null) {
                    return Decision::deny('no_consent', 403, 'profile_required_for_consent');
                }

                $hasConsent = ConsentRecord::query()
                    ->where('profile_id', $resource->profileId)
                    ->where('purpose', $requiredPurpose)
                    ->whereNull('withdrawn_at')
                    ->exists();

                if (! $hasConsent) {
                    return Decision::deny('no_consent', 403, 'consent_missing');
                }
            }

            return Decision::allow();
        });
    }

    /**
     * `self_membership` branch: the actor's own user id is the whole scope.
     *
     * There is no workspace and therefore no role gate: Matrix E marks all
     * three notification actions `A` for every role, and the resource can only
     * ever be the actor's own rows. A platform role adds no standing access
     * here because the actor still only sees their own records (ABD-011).
     *
     * @param  array{scope: ?string, action: string}  $mapping
     */
    private function evaluateSelfService(
        ?User $actor,
        string $permission,
        ResourceRef $resource,
        array $mapping,
    ): Decision {
        if ($actor === null) {
            return Decision::deny('unauthenticated', 401, 'not_authenticated');
        }

        // Defence in depth: a null-scope permission is not automatically a
        // self-service permission. Only the frozen Matrix E list qualifies.
        if ($mapping['scope'] !== null || ! $this->supportsSelfService($permission)) {
            return Decision::deny('unknown_permission', 500, 'permission_not_self_service');
        }

        // The resource is built server-side from the authenticated session, so
        // a mismatch means a programming error, not client input (N-01).
        if ($resource->selfUserId !== $actor->id) {
            return Decision::deny('forbidden', 403, 'self_scope_mismatch');
        }

        return Decision::allow();
    }

    /**
     * Run the callback with the workspace's tenant/workspace context, unless
     * the request already carries exactly that context (the usual case behind
     * `workspace.context`).
     */
    private function withinWorkspace(Workspace $workspace, callable $callback): mixed
    {
        if (TenantContext::matches($workspace->tenant_id, $workspace->id)) {
            return $callback();
        }

        return TenantContext::run($workspace->tenant_id, $workspace->id, $callback);
    }

    /**
     * @param  list<array{permission: string, profile_id: ?string}>  $checks
     * @return list<Decision>
     */
    private function evaluateBatch(?User $actor, Workspace $workspace, array $checks): array
    {
        $count = count($checks);

        // [1] Authentication.
        if ($actor === null) {
            return array_fill(0, $count, Decision::deny('unauthenticated', 401, 'not_authenticated'));
        }

        // [3] Active membership. A non-member is indistinguishable from an
        // unknown workspace: both are 404 (no enumeration).
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            return array_fill(0, $count, Decision::deny('out_of_scope', 404, 'not_a_member'));
        }

        $profileIds = [];

        foreach ($checks as $check) {
            $profileId = $check['profile_id'] ?? null;

            if (is_string($profileId) && Str::isUuid($profileId)) {
                $profileIds[$profileId] = true;
            }
        }

        $profileIds = array_keys($profileIds);

        $profiles = $profileIds === []
            ? collect()
            : HealthProfile::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $profileIds)
                ->get()
                ->keyBy('id');

        $personIds = $profiles->pluck('person_id')->filter()->unique()->values()->all();

        $ownedPersonIds = $personIds === []
            ? []
            : array_fill_keys(
                Person::query()
                    ->whereIn('id', $personIds)
                    ->where('user_id', $actor->id)
                    ->pluck('id')
                    ->all(),
                true,
            );

        $grantsByProfile = [];

        if ($profileIds !== []) {
            $grants = AccessGrant::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('profile_id', $profileIds)
                ->where('grantee_user_id', $actor->id)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->get();

            foreach ($grants as $grant) {
                $grantsByProfile[(string) $grant->profile_id][] = $grant;
            }
        }

        // [6] Consent rows that could be required by any check, fetched once.
        $purposes = [];

        foreach ($checks as $check) {
            foreach ($this->requiredConsentPurposes((string) $check['permission'], null) as $purpose) {
                $purposes[$purpose] = true;
            }
        }

        $consents = [];

        if ($purposes !== [] && $profileIds !== []) {
            $rows = ConsentRecord::query()
                ->whereIn('profile_id', $profileIds)
                ->whereIn('purpose', array_keys($purposes))
                ->whereNull('withdrawn_at')
                ->get();

            foreach ($rows as $row) {
                $consents[$row->profile_id.'|'.$row->purpose] = true;
            }
        }

        $decisions = [];

        foreach ($checks as $check) {
            $permission = (string) $check['permission'];
            $mapping = $this->mappingFor($permission);

            // [2] The permission must exist in the frozen catalog and have an
            // explicit scope/action mapping. Missing = server bug, never 403.
            if (! $this->catalog->has($permission) || $mapping === null || ! $this->catalog->allowsAction($mapping['action'])) {
                $decisions[] = Decision::deny('unknown_permission', 500, 'permission_not_mapped');

                continue;
            }

            // [4] Role gate.
            if (! $this->gate->permits($membership->role, $permission, $mapping['action'])) {
                $decisions[] = Decision::deny('forbidden', 403, 'role_cannot_hold_permission');

                continue;
            }

            $profileId = $check['profile_id'] ?? null;

            // [5] Resource scope.
            if ($profileId !== null) {
                $profile = is_string($profileId) ? $profiles->get($profileId) : null;

                if (! is_string($profileId) || ! Str::isUuid($profileId) || $profile === null) {
                    $decisions[] = Decision::deny('out_of_scope', 404, 'profile_out_of_scope');

                    continue;
                }

                if ($mapping['scope'] !== null) {
                    $owner = isset($ownedPersonIds[(string) $profile->person_id]);
                    $granted = false;

                    if (! $owner) {
                        foreach ($grantsByProfile[(string) $profile->id] ?? [] as $grant) {
                            if ($this->grants->isUsable($grant, $mapping['scope'], $mapping['action'])) {
                                $granted = true;

                                break;
                            }
                        }
                    }

                    if (! $owner && ! $granted) {
                        $decisions[] = Decision::deny('forbidden', 403, 'scope_not_granted');

                        continue;
                    }
                }
            }

            // [6] Consent.
            $consent = $this->batchConsentDecision($permission, is_string($profileId) ? $profileId : null, $consents);

            if ($consent !== null) {
                $decisions[] = $consent;

                continue;
            }

            $decisions[] = Decision::allow();
        }

        return $decisions;
    }

    /**
     * @param  array<string, bool>  $consents  "profile_id|purpose" => true
     */
    private function batchConsentDecision(string $permission, ?string $profileId, array $consents): ?Decision
    {
        foreach ($this->requiredConsentPurposes($permission, null) as $purpose) {
            if ($profileId === null) {
                return Decision::deny('no_consent', 403, 'profile_required_for_consent');
            }

            if (! isset($consents[$profileId.'|'.$purpose])) {
                return Decision::deny('no_consent', 403, 'consent_missing');
            }
        }

        return null;
    }

    /**
     * One audit row per decision, appended as a single chain segment.
     *
     * @param  list<array{permission: string, profile_id: ?string}>  $checks
     * @param  list<Decision>  $decisions
     */
    private function auditBatch(?User $actor, Workspace $workspace, array $checks, array $decisions): void
    {
        $events = [];

        foreach ($checks as $index => $check) {
            $decision = $decisions[$index];
            $permission = (string) $check['permission'];
            $profileId = $check['profile_id'] ?? null;
            $targetId = is_string($profileId) && $profileId !== '' ? $profileId : $workspace->id;

            $events[] = [
                'actor_id' => $actor?->id,
                'actor_type' => $actor === null ? 'anonymous' : 'user',
                'action' => $permission !== '' ? $permission : 'unknown_permission',
                'target_type' => is_string($profileId) && $profileId !== '' ? 'health_profile' : 'workspace',
                'target_id' => $targetId,
                'result' => $decision->allowed() ? 'allow' : 'deny',
                'reason_code' => $decision->allowed() ? null : $decision->code(),
                'metadata' => ['reason' => $decision->reason()],
            ];
        }

        $this->audit->recordMany($events);
    }

    /**
     * @param  array{scope: ?string, action: string}  $mapping
     */
    private function isProfileOwner(User $actor, HealthProfile $profile): bool
    {
        return Person::query()
            ->whereKey($profile->person_id)
            ->where('user_id', $actor->id)
            ->exists();
    }

    private function hasUsableGrant(User $actor, HealthProfile $profile, string $scope, string $action): bool
    {
        $grants = AccessGrant::query()
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->where('grantee_user_id', $actor->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->get();

        foreach ($grants as $grant) {
            if ($this->grants->isUsable($grant, $scope, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function requiredConsentPurposes(string $permission, ?string $purpose): array
    {
        $purposes = [];

        $required = self::CONSENT_REQUIRED_PURPOSES[$permission] ?? null;

        if ($required !== null) {
            $purposes[] = $required;
        }

        if (
            $purpose !== null
            && in_array($purpose, $this->catalog->consentPurposes(), true)
            && ! in_array($purpose, $purposes, true)
        ) {
            $purposes[] = $purpose;
        }

        return $purposes;
    }

    /**
     * Every decision — allow and deny — produces exactly one append-only
     * security audit row. The result is written only after the pipeline has
     * completed, so a crashed pipeline cannot leave a misleading entry.
     */
    private function auditDecision(
        ?User $actor,
        string $permission,
        ResourceRef $resource,
        Decision $decision,
    ): void {
        if ($resource->isSelfService()) {
            $this->auditSelfServiceDecision($actor, $permission, $decision);

            return;
        }

        $workspace = $this->workspaces->resolve((string) $resource->workspaceId);

        if (! $workspace instanceof Workspace) {
            // No trustworthy tenant context: there is nowhere safe to write,
            // and we must not invent one (N-01 / ADR-013).
            return;
        }

        TenantContext::run($workspace->tenant_id, $workspace->id, function () use (
            $actor,
            $permission,
            $resource,
            $decision,
        ): void {
            $this->audit->record([
                'actor_id' => $actor?->id,
                'actor_type' => $actor === null ? 'anonymous' : 'user',
                'action' => $permission !== '' ? $permission : 'unknown_permission',
                'target_type' => $resource->profileId === null ? 'workspace' : 'health_profile',
                'target_id' => $resource->profileId ?? $resource->workspaceId,
                'result' => $decision->allowed() ? 'allow' : 'deny',
                'reason_code' => $decision->allowed() ? null : $decision->code(),
                'metadata' => ['reason' => $decision->reason()],
            ]);
        });
    }

    /**
     * A `/me` decision has no workspace, but an allow is still invalid without
     * an audit row (F-11). `security_audit_logs.tenant_id` is mandatory while
     * `workspace_id` is nullable by design (platform/system events), so the
     * row is chained into the tenant directory (default tenant first) with
     * `workspace_id = null`. That is server-side data only; the actor cannot
     * influence it (N-01).
     */
    private function auditSelfServiceDecision(
        ?User $actor,
        string $permission,
        Decision $decision,
    ): void {
        $tenantId = Tenant::query()
            ->orderByRaw("case when slug = 'default' then 0 else 1 end")
            ->orderBy('id')
            ->value('id');

        if (! is_string($tenantId) || $tenantId === '') {
            throw new \RuntimeException(
                'Self-service authorization audit requires a tenant directory row (F-11).'
            );
        }

        TenantContext::run($tenantId, '', function () use ($actor, $permission, $decision): void {
            $this->audit->record([
                'actor_id' => $actor?->id,
                'actor_type' => $actor === null ? 'anonymous' : 'user',
                'action' => $permission !== '' ? $permission : 'unknown_permission',
                'target_type' => 'user',
                // `target_id` is uuid in the ledger; user ids are bigint.
                'target_id' => null,
                'result' => $decision->allowed() ? 'allow' : 'deny',
                'reason_code' => $decision->allowed() ? null : $decision->code(),
                'metadata' => [
                    'reason' => $decision->reason(),
                    'scope' => 'self_membership',
                ],
            ]);
        });
    }
}
