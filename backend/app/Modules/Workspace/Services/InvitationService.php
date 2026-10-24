<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\Workspace\Exceptions\AlreadyMemberException;
use App\Modules\Workspace\Exceptions\InvitationNotFoundException;
use App\Modules\Workspace\Exceptions\InvitationUnavailableException;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Workspace invitations.
 *
 * The plaintext token exists only in the HTTP response that creates it. The
 * database stores `sha256(token)`, lookup is by hash with `hash_equals`
 * semantics through the unique index, and the token is single use
 * (`accepted_at`) with a seven-day default lifetime. An invitation cancelled
 * by its inviter is marked `revoked_at`; the row is kept (soft state) so a
 * revoked token still resolves and is refused, and so the audit trail of the
 * issued token survives.
 */
class InvitationService
{
    public const TTL_DAYS = 7;

    /** Roles a workspace invitation may hand out (never a platform role). */
    public const INVITABLE_ROLES = [
        'workspace_admin',
        'caregiver',
        'healthcare_professional',
        'legal_guardian',
        'viewer',
    ];

    public function __construct(private readonly BusinessAuditLogger $audit) {}

    /**
     * @return array{invitation: Invitation, token: string}
     */
    public function issue(Workspace $workspace, User $actor, string $email, string $role): array
    {
        if (! in_array($role, self::INVITABLE_ROLES, true)) {
            throw new InvalidArgumentException('Role cannot be invited.');
        }

        $token = bin2hex(random_bytes(32));

        $invitation = Invitation::query()->create([
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'email' => $email,
            'role' => $role,
            'token' => self::hashToken($token),
            'expires_at' => now()->addDays(self::TTL_DAYS),
            'accepted_at' => null,
            'created_by' => $actor->id,
        ]);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Pending, unexpired invitations of one workspace.
     *
     * Expired, accepted and revoked rows are excluded here (not filtered by
     * the client). The RLS workspace context opened by `workspace.context`
     * already scopes the query; the explicit predicates are defence in depth
     * and make the intent readable.
     *
     * The audit row carries only the count: a list of invitee addresses in an
     * append-only log would be exactly the harvestable email list N-02 forbids.
     *
     * @return Collection<int, Invitation>
     */
    public function listPending(Workspace $workspace, User $actor): Collection
    {
        $invitations = Invitation::query()
            ->with('creator')
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->audit->record([
            'actor_id' => $actor->id,
            'actor_type' => 'user',
            'action' => 'workspace.invitation.list',
            'target_type' => 'workspace',
            'target_id' => $workspace->id,
            'result' => 'allow',
            'metadata' => ['pending_count' => $invitations->count()],
        ]);

        return $invitations;
    }

    /**
     * Revoke a pending invitation. Idempotent: revoking an already revoked or
     * expired invitation is a successful no-op (a double tap cannot error).
     *
     * The invitation is found by id *and* by workspace, so a foreign id is
     * indistinguishable from a missing one ({@see InvitationNotFoundException}
     * -> 404, never 403).
     *
     * Exactly one business audit row is written for every existing invitation,
     * including an idempotent repeat. The invitee address is stored only as a
     * keyed digest (`email_hash`), never in clear text.
     *
     * @return bool true when this call changed the row to revoked
     *
     * @throws InvitationNotFoundException
     */
    public function revoke(Workspace $workspace, string $invitationId, User $actor): bool
    {
        $invitation = Invitation::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->whereKey($invitationId)
            ->lockForUpdate()
            ->first();

        if (! $invitation instanceof Invitation) {
            throw InvitationNotFoundException::make();
        }

        $changed = false;

        if ($invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            $changed = true;
        }

        $this->audit->record([
            'actor_id' => $actor->id,
            'actor_type' => 'user',
            'action' => 'workspace.invitation.revoke',
            'target_type' => 'workspace_invitation',
            'target_id' => $invitation->id,
            'result' => 'allow',
            'metadata' => [
                'email_hash' => self::hashEmail($invitation->email),
                'changed' => $changed,
            ],
        ]);

        return $changed;
    }

    /**
     * Resolve a plaintext token to its invitation without a workspace context.
     *
     * `invitations` is RLS-protected, so the trusted tenant/workspace
     * directory is walked exactly like `WorkspaceResolver` does for a request
     * workspace. A token never matches in more than one workspace.
     */
    public function resolve(string $plainToken): ?Invitation
    {
        if ($plainToken === '') {
            return null;
        }

        $hash = self::hashToken($plainToken);

        $tenantIds = Tenant::query()
            ->orderByRaw("case when slug = 'default' then 0 else 1 end")
            ->orderBy('id')
            ->pluck('id');

        foreach ($tenantIds as $tenantId) {
            $invitation = DB::transaction(function () use ($tenantId, $hash): ?Invitation {
                TenantContext::set($tenantId, '');

                try {
                    /** @var Collection<int, Workspace> $workspaces */
                    $workspaces = Workspace::query()->get();

                    foreach ($workspaces as $workspace) {
                        TenantContext::set($tenantId, $workspace->id);

                        $found = Invitation::query()
                            ->where('token', $hash)
                            ->first();

                        if ($found instanceof Invitation) {
                            return $found;
                        }
                    }

                    return null;
                } finally {
                    TenantContext::clear();
                }
            });

            if ($invitation instanceof Invitation) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * @return array{workspace: Workspace, membership: WorkspaceMembership}
     */
    public function accept(Invitation $invitation, User $user): array
    {
        return DB::transaction(function () use ($invitation, $user): array {
            TenantContext::set($invitation->tenant_id, $invitation->workspace_id);

            try {
                $workspace = Workspace::query()->whereKey($invitation->workspace_id)->first();

                if (! $workspace instanceof Workspace) {
                    throw InvitationUnavailableException::make();
                }

                $existing = WorkspaceMembership::query()
                    ->where('workspace_id', $invitation->workspace_id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof WorkspaceMembership) {
                    throw AlreadyMemberException::make();
                }

                // Lock the invitation so a concurrent revoke and accept cannot
                // both succeed: whichever waits sees the other's committed
                // state (F-08 revocation takes effect on every path).
                $locked = Invitation::query()
                    ->whereKey($invitation->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof Invitation
                    || $locked->accepted_at !== null
                    || $locked->revoked_at !== null
                    || $locked->expires_at === null
                    || $locked->expires_at->isPast()) {
                    throw InvitationUnavailableException::make();
                }

                $membership = WorkspaceMembership::query()->forceCreate([
                    'id' => Uuid7::generate(),
                    'tenant_id' => $locked->tenant_id,
                    'workspace_id' => $locked->workspace_id,
                    'user_id' => $user->id,
                    'role' => $locked->role,
                    'status' => 'active',
                ]);

                $locked->accepted_at = now();
                $locked->save();

                return ['workspace' => $workspace, 'membership' => $membership];
            } finally {
                TenantContext::clear();
            }
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Pseudonymous invitee reference for the audit trail.
     *
     * Keyed with the application key so the log value is not a plain SHA-256
     * of an address that could be recovered with a dictionary of likely
     * emails. The same address always hashes to the same value, which is what
     * an investigator needs; the address itself is never written (N-02).
     */
    public static function hashEmail(?string $email): string
    {
        return hash_hmac('sha256', strtolower(trim((string) $email)), (string) config('app.key'));
    }
}
