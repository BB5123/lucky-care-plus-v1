<?php

namespace App\Http\Controllers;

use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Protected test endpoint for the authorization pipeline.
 *
 * This controller makes no permission decision: `workspace.context` resolved
 * the workspace and `authorize.permission:workspace.read` already obtained an
 * allow from Authorizer before this method ran.
 */
final class WorkspacePingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('workspace');

        return response()->json([
            'data' => [
                'ok' => true,
                'workspace_id' => $workspace->id,
                'tenant_id' => $workspace->tenant_id,
            ],
        ]);
    }
}
