<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Modules\Dashboard\Application\DashboardAssembler;
use App\Modules\Dashboard\Domain\DashboardNotFoundException;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The single dashboard aggregate (ADR-010).
 *
 * There is deliberately **no** `authorize.permission` middleware on this
 * route: one route-level permission cannot express "this actor may see the
 * family card and the task card but not the vitals card". ADR-010 and CRM-023
 * require the controller to authorize **every card separately**, which it
 * does through `DashboardAssembler` → `Authorizer::authorizeBatch`. The batch
 * still enforces membership, role, scope/grant, consent, and writes one
 * `security_audit_logs` row per card decision.
 *
 * The route still carries `auth:sanctum` and `workspace.context`; the latter
 * resolves the workspace server-side and returns 404 to non-members.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardAssembler $assembler): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $actor = $request->user();

        if (! $workspace instanceof Workspace || ! $actor instanceof User) {
            return ProblemDetails::make(
                $request,
                404,
                'out_of_scope',
                'Resource not accessible',
                'ไม่พบข้อมูลที่ร้องขอ',
            );
        }

        $profileId = $request->query('profile_id');

        if ($profileId === '') {
            $profileId = null;
        }

        // A malformed profile id is indistinguishable from one the actor may
        // not see: 404, never 422 (AP-008 / N-01).
        if ($profileId !== null && (! is_string($profileId) || ! Str::isUuid($profileId))) {
            return ProblemDetails::make(
                $request,
                404,
                'out_of_scope',
                'Resource not accessible',
                'ไม่พบข้อมูลที่ร้องขอ',
            );
        }

        // `bypass_cache=1` is for tests and trusted internal callers. It needs
        // no extra permission: the caller still passes the same per-card
        // authorization, it only skips the 30-second read model cache.
        $bypassCache = filter_var($request->query('bypass_cache', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $data = $assembler->assemble($workspace, $actor, $profileId, $bypassCache);
        } catch (DashboardNotFoundException) {
            return ProblemDetails::make(
                $request,
                404,
                'out_of_scope',
                'Resource not accessible',
                'ไม่พบข้อมูลที่ร้องขอ',
            );
        }

        return response()->json([
            'data' => $data,
            'meta' => ['bypass_cache' => $bypassCache],
        ]);
    }
}
