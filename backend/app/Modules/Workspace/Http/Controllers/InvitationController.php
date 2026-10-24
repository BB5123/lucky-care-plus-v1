<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Modules\Workspace\Exceptions\AlreadyMemberException;
use App\Modules\Workspace\Exceptions\InvitationNotFoundException;
use App\Modules\Workspace\Exceptions\InvitationUnavailableException;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\InvitationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invitations: issue, list pending, revoke, accept.
 *
 * The token returned by `store` is the only copy of the secret: only its
 * SHA-256 hash is persisted. Acceptance is single use; an expired, revoked or
 * unknown token is indistinguishable from a missing one (404).
 *
 * Listing and revoking are gated by `workspace.member.invite` on the route,
 * not by `workspace.member.read`: cancelling an invitation is the inverse of
 * issuing it, and the pending-invitee list is the same artefact (addresses the
 * actor supplied) that the invite permission already grants control over.
 * `.read` is the weaker "view the member list" permission and is not reused
 * here. The baseline has no dedicated revoke-invitation permission, and
 * inventing one is forbidden.
 */
final class InvitationController extends Controller
{
    public function index(Request $request, InvitationService $invitations): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('workspace');

        $pending = $invitations->listPending($workspace, $request->user());

        return response()->json([
            'data' => $pending
                ->map(fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                    'created_at' => $invitation->created_at?->toIso8601String(),
                    // A display name, never the inviter's id.
                    'invited_by' => $invitation->creator?->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function destroy(Request $request, InvitationService $invitations): Response
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('workspace');

        // Route parameters are read from the request (project convention),
        // not through a positional controller argument: with two route
        // parameters a primitive argument would bind by position, not by name.
        $invitationId = (string) $request->route('invitation');

        try {
            $invitations->revoke($workspace, $invitationId, $request->user());
        } catch (InvitationNotFoundException) {
            return $this->notFound($request);
        }

        // 204 for a real revoke and for an idempotent repeat alike: a double
        // tap must not surface an error.
        return response()->noContent();
    }

    public function store(Request $request, InvitationService $invitations): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(InvitationService::INVITABLE_ROLES)],
        ]);

        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('workspace');

        $result = $invitations->issue(
            $workspace,
            $request->user(),
            $validated['email'],
            $validated['role'],
        );

        return response()->json([
            'data' => [
                'id' => $result['invitation']->id,
                'email' => $result['invitation']->email,
                'role' => $result['invitation']->role,
                'expires_at' => $result['invitation']->expires_at?->toIso8601String(),
                'token' => $result['token'],
            ],
        ], 201);
    }

    public function accept(Request $request, string $token, InvitationService $invitations): JsonResponse
    {
        $invitation = $invitations->resolve($token);

        if ($invitation === null || $invitation->expires_at->isPast()) {
            return $this->notFound($request);
        }

        try {
            $result = $invitations->accept($invitation, $request->user());
        } catch (AlreadyMemberException) {
            return ProblemDetails::make(
                $request,
                409,
                'domain_rule',
                'Domain rule violation',
                'เป็นสมาชิกของ workspace นี้อยู่แล้ว',
            );
        } catch (InvitationUnavailableException) {
            return $this->notFound($request);
        } catch (QueryException $e) {
            // Concurrent acceptance lost the unique-membership race.
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }

            return ProblemDetails::make(
                $request,
                409,
                'domain_rule',
                'Domain rule violation',
                'เป็นสมาชิกของ workspace นี้อยู่แล้ว',
            );
        }

        return response()->json([
            'data' => [
                'workspace' => [
                    'id' => $result['workspace']->id,
                    'name' => $result['workspace']->name,
                ],
                'role' => $result['membership']->role,
                'status' => $result['membership']->status,
            ],
        ]);
    }

    private function notFound(Request $request): JsonResponse
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
