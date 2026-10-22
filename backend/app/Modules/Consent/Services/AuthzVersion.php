<?php

namespace App\Modules\Consent\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Per-workspace authorization version used to invalidate cached decisions and
 * aggregate payloads (F-08, `.ai/03-AUTHORIZATION.md §7`).
 *
 * Why a counter and not tag-based invalidation: the cache store must stay
 * driver-agnostic (array in tests, database/Redis in production) and the
 * dashboard key must remain a plain string. Bumping a small integer is the
 * simplest mechanism that makes every previously cached payload unreachable
 * the moment a grant or consent mutates.
 *
 * Gap (documented, bounded): membership role changes do not currently bump
 * the counter. The dashboard TTL is 30 seconds, so a role downgrade can be
 * served from cache for at most one TTL; request-time membership status is
 * still checked by `workspace.context` on every call.
 */
final class AuthzVersion
{
    public static function key(string $workspaceId): string
    {
        return 'authz_version:'.$workspaceId;
    }

    public static function current(string $workspaceId): int
    {
        $value = Cache::get(self::key($workspaceId));

        return is_numeric($value) ? (int) $value : 1;
    }

    public static function bump(string $workspaceId): void
    {
        $key = self::key($workspaceId);

        // Array/database stores return false when the key is absent; start the
        // counter at 1 in that case. A concurrent first bump may lose one
        // increment, which is harmless: both writers only need a value that
        // differs from the cached keys' version.
        if (Cache::increment($key) === false) {
            Cache::forever($key, 1);
        }
    }
}
