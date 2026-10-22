<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\Workspace\Exceptions\AlreadyMemberException;
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
 * (`accepted_at`) with a seven-day default lifetime.
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

                if ($invitation->accepted_at !== null) {
                    throw InvitationUnavailableException::make();
                }

                $membership = WorkspaceMembership::query()->forceCreate([
                    'id' => Uuid7::generate(),
                    'tenant_id' => $invitation->tenant_id,
                    'workspace_id' => $invitation->workspace_id,
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                    'status' => 'active',
                ]);

                $invitation->accepted_at = now();
                $invitation->save();

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
}
