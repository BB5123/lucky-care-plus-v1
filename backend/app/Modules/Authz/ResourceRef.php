<?php

namespace App\Modules\Authz;

/**
 * The server-resolved resource an authorization decision is about.
 *
 * Constructors are deliberately named:
 *  - `forWorkspace()` for a workspace-level permission (no profile);
 *  - `forProfile()` for a profile-scoped permission;
 *  - `forSelfMembership()` for a self-service permission that belongs to the
 *    actor alone and therefore has no workspace in the route (`/me/...`).
 *
 * Values must come from trusted session/route resolution or persisted data,
 * never from a request body (N-01).
 */
final readonly class ResourceRef
{
    private function __construct(
        public ?string $workspaceId,
        public ?string $profileId,
        public ?int $selfUserId = null,
    ) {}

    public static function forWorkspace(string $workspaceId): self
    {
        return new self($workspaceId, null);
    }

    public static function forProfile(string $workspaceId, string $profileId): self
    {
        return new self($workspaceId, $profileId);
    }

    /**
     * The actor's own `self_membership` scope, expressed without a workspace.
     *
     * Baseline `PERMISSION_CATALOG.md §4.11` scopes the three notification
     * permissions to `self_membership:<membership_id>`. Preferences and
     * deliveries are per user rather than per workspace, and `/me` routes carry
     * no `{workspace}` (ADR-005), so the scope is expressed as the authenticated
     * actor's own identity. The Authorizer only accepts this resource for the
     * frozen self-membership allowlist, and every query additionally filters
     * by that same user id.
     */
    public static function forSelfMembership(int $userId): self
    {
        return new self(null, null, $userId);
    }

    public function isSelfService(): bool
    {
        return $this->selfUserId !== null;
    }
}
