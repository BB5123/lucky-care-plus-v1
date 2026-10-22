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
        // One round trip for both settings. Both values stay parameterised and
        // transaction-local (`set_config(..., true)` = SET LOCAL).
        DB::selectOne(
            'select set_config(?, ?, true) as tenant, set_config(?, ?, true) as workspace',
            [self::TENANT_KEY, $tenantId, self::WORKSPACE_KEY, $workspaceId],
        );
    }

    public static function clear(): void
    {
        // Empty string is treated as absent by `current()` and by the RLS
        // policies (`nullif(current_setting(...), '')`), so this is the same
        // fail-closed state as `reset` with one round trip instead of two.
        DB::statement("select set_config('app.tenant_id', '', false), set_config('app.workspace_id', '', false)");
    }

    /**
     * Read the tenant currently set on the connection (null when absent).
     */
    /**
     * True when the connection already carries exactly this context.
     *
     * Batch enforcement paths (e.g. the dashboard read model) call this to
     * avoid re-entering `run()` when `workspace.context` has already opened
     * the transaction. One round trip instead of six.
     */
    public static function matches(string $tenantId, string $workspaceId): bool
    {
        $context = self::pair();

        return $context['tenant_id'] === $tenantId && $context['workspace_id'] === $workspaceId;
    }

    /**
     * Both context values in one round trip. Used by batch writers (audit)
     * that already need both.
     *
     * @return array{tenant_id: ?string, workspace_id: ?string}
     */
    public static function pair(): array
    {
        $row = DB::selectOne(
            "select current_setting('app.tenant_id', true) as tenant_id, "
            ."current_setting('app.workspace_id', true) as workspace_id"
        );

        return [
            'tenant_id' => self::normalise($row->tenant_id ?? null),
            'workspace_id' => self::normalise($row->workspace_id ?? null),
        ];
    }

    public static function tenantId(): ?string
    {
        return self::current(self::TENANT_KEY);
    }

    /**
     * Read the workspace currently set on the connection (null when absent).
     */
    public static function workspaceId(): ?string
    {
        return self::current(self::WORKSPACE_KEY);
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

        return self::normalise($value);
    }

    private static function normalise(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
