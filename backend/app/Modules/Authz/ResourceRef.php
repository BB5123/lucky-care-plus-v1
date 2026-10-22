<?php

namespace App\Modules\Authz;

/**
 * The server-resolved resource an authorization decision is about.
 *
 * Constructors are deliberately named: a workspace-level permission never
 * carries a profile id, and a profile-scoped permission always carries one.
 * Values must come from trusted route resolution / persisted data, never from
 * a request body (N-01).
 */
final readonly class ResourceRef
{
    private function __construct(
        public string $workspaceId,
        public ?string $profileId,
    ) {}

    public static function forWorkspace(string $workspaceId): self
    {
        return new self($workspaceId, null);
    }

    public static function forProfile(string $workspaceId, string $profileId): self
    {
        return new self($workspaceId, $profileId);
    }
}
