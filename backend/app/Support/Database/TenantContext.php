<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Sets the PostgreSQL session context used by Row Level Security policies.
 *
 * SECURITY: the tenant and workspace identifiers MUST come from server-side
 * request context (the authenticated session resolved from the database, or a
 * trusted job payload). They must NEVER be taken from client input such as a
 * header, query string, or request body (Constitution N-01).
 *
 * Both values must be set inside the same transaction as the queries they
 * guard. `set_config(..., true)` is the parameterisable equivalent of
 * `SET LOCAL`, so it is scoped to the current transaction and disappears on
 * commit or rollback. When no value is set, the RLS policy evaluates to false
 * and all rows are denied (fail closed, F-09).
 */
final class TenantContext
{
    private const TENANT_KEY = 'app.tenant_id';

    private const WORKSPACE_KEY = 'app.workspace_id';

    public static function set(?string $tenantId, ?string $workspaceId): void
    {
        DB::selectOne('select set_config(?, ?, true)', [self::TENANT_KEY, $tenantId]);
        DB::selectOne('select set_config(?, ?, true)', [self::WORKSPACE_KEY, $workspaceId]);
    }

    public static function clear(): void
    {
        DB::statement('reset app.tenant_id');
        DB::statement('reset app.workspace_id');
    }

    /**
     * Run a callback with the given tenant/workspace context.
     *
     * The context is always restored in a `finally`, so a callback can never
     * leak its identity into the code that runs after it: any context that was
     * active before the call is reinstated, and when there was none the context
     * is cleared (fail closed). The callback's return value is passed through.
     *
     * As with `set()`, the identifiers MUST be resolved server-side; never pass
     * values that originated from client input.
     */
    public static function run(string $tenantId, string $workspaceId, callable $callback): mixed
    {
        $previousTenantId = self::current(self::TENANT_KEY);
        $previousWorkspaceId = self::current(self::WORKSPACE_KEY);

        self::set($tenantId, $workspaceId);

        try {
            return $callback();
        } finally {
            if ($previousTenantId === null) {
                self::clear();
            } else {
                self::set($previousTenantId, $previousWorkspaceId);
            }
        }
    }

    /**
     * Read the context value currently set on this connection, treating both
     * "never set" (null) and "reset" (empty string) as absent.
     */
    private static function current(string $key): ?string
    {
        $value = DB::selectOne('select current_setting(?, true) as value', [$key])->value;

        return $value === null || $value === '' ? null : $value;
    }
}
