<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace creation and detail.
 *
 * The creator becomes `workspace_admin`. No permission check is needed for
 * creation (any authenticated account may start a workspace); every later
 * endpoint declares a permission ID and lets `Authorizer` decide.
 */
final class WorkspaceController extends Controller
{
    public function store(Request $request, WorkspaceService $workspaces): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $result = $workspaces->createFor($request->user(), $validated['name']);

        return response()->json([
            'data' => [
                'id' => $result['workspace']->id,
                'name' => $result['workspace']->name,
                'slug' => $result['workspace']->slug,
                'role' => $result['membership']->role,
                'status' => $result['membership']->status,
            ],
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('workspace');
        $membership = $request->attributes->get('membership');

        return response()->json([
            'data' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => $membership?->role,
                'break_glass_enabled' => $workspace->break_glass_enabled,
            ],
        ]);
    }
}
