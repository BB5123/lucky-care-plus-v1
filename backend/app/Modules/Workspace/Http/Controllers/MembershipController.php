<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Membership listing. Authorization already happened in
 * `authorize.permission:workspace.member.read`; rows are additionally filtered
 * by the RLS workspace context opened by `workspace.context`.
 */
final class MembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('workspace');

        $members = WorkspaceMembership::query()
            ->with('user')
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkspaceMembership $membership): array => [
                'id' => $membership->user_id,
                'user_id' => $membership->user_id,
                'membership_id' => $membership->id,
                'name' => $membership->user?->name,
                'role' => $membership->role,
                'status' => $membership->status,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $members]);
    }
}
