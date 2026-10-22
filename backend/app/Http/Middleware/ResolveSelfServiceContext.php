<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Support\Database\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opens the transaction-local `app.user_id` context for `/me/...` routes.
 *
 * There is intentionally no `{workspace}` here (ADR-005): preferences and
 * deliveries are user-owned. The setting lets the RLS policy on
 * `notification_deliveries` keep one user's rows hidden from another user in
 * the same workspace, while the query layer filters by `recipient_user_id` as
 * well. It is resolved from the authenticated session, never from the request
 * body or a header (N-01).
 *
 * As with `workspace.context`, `set_config(..., true)` is transaction-local,
 * so the request must run inside the transaction that sets it.
 */
final class ResolveSelfServiceContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return ProblemDetails::make(
                $request,
                401,
                'unauthenticated',
                'Authentication required',
                'กรุณายืนยันตัวตนก่อนเข้าถึงข้อมูล',
            );
        }

        return DB::transaction(function () use ($next, $request, $actor): Response {
            TenantContext::setActor($actor->id);

            try {
                return $next($request);
            } finally {
                try {
                    TenantContext::clearActor();
                } catch (Throwable) {
                    // ROLLBACK already discarded the transaction-local value.
                }
            }
        });
    }
}
