<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProblemDetails;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Modules\Workspace\Services\WorkspaceResolver;
use App\Support\Database\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolves `{workspace}` and opens the tenant-scoped request transaction.
 *
 * The workspace UUID comes only from the route (server-side resolution). A
 * request body or header never supplies tenant/workspace identity (N-01).
 *
 * `TenantContext::set()` uses `set_config(..., true)`, which is the
 * transaction-scoped equivalent of `SET LOCAL`. The value disappears at
 * COMMIT/ROLLBACK, so the request itself must run inside the transaction that
 * sets it. On success the transaction commits; on an exception it rolls back.
 * The context is cleared in `finally` as defence in depth (including for
 * connection reuse in tests and long-lived workers).
 */
final class ResolveWorkspaceContext
{
    public function __construct(private readonly WorkspaceResolver $workspaces) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $request->route('workspace');

        if (! is_string($workspaceId) || $workspaceId === '') {
            return $this->notFound($request);
        }

        $workspace = $this->workspaces->resolve($workspaceId);

        if (! $workspace instanceof Workspace) {
            return $this->notFound($request);
        }

        $actor = $request->user();

        if ($actor === null) {
            return $this->notFound($request);
        }

        return DB::transaction(function () use ($request, $next, $workspace, $actor): Response {
            TenantContext::set($workspace->tenant_id, $workspace->id);

            try {
                $membership = WorkspaceMembership::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('user_id', $actor->id)
                    ->where('status', 'active')
                    ->first();

                if (! $membership instanceof WorkspaceMembership) {
                    return $this->notFound($request);
                }

                $request->attributes->set('workspace', $workspace);
                $request->attributes->set('membership', $membership);

                return $next($request);
            } finally {
                // If the connection is in an aborted transaction, `reset` is
                // rejected; ROLLBACK discards the transaction-local setting
                // anyway, so swallowing that specific cleanup error is safe.
                try {
                    TenantContext::clear();
                } catch (Throwable) {
                    // no-op: rollback clears the transaction-scoped context
                }
            }
        });
    }

    private function notFound(Request $request): Response
    {
        return ProblemDetails::make(
            $request,
            404,
            'out_of_scope',
            'Resource not accessible',
            'ไม่พบข้อมูลที่ร้องขอ',
        );
    }
}
