<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProblemDetails;
use App\Modules\Authz\Authorizer;
use App\Modules\Authz\ResourceRef;
use App\Modules\Workspace\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin declaration layer over Authorizer.
 *
 * Chosen instead of a Gate helper because route middleware:
 *  - runs after `workspace.context`, so workspace/membership resolution and
 *    the RLS transaction are already in place;
 *  - reads typed route parameters (`workspace`, `profile`) without controllers
 *    manually rebuilding a ResourceRef or forgetting a check;
 *  - executes before controller code, so a denied request cannot produce a
 *    side effect (no writes, no jobs, no signed links).
 *
 * Usage: `->middleware('authorize.permission:care_task.read')`.
 * Purpose is never read from the request body/header; domain code that has a
 * trusted purpose must pass it explicitly through Authorizer.
 */
final class EnsureAuthorized
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $workspace = $request->attributes->get('workspace');

        if (! $workspace instanceof Workspace) {
            return ProblemDetails::make(
                $request,
                503,
                'authorization_unavailable',
                'Authorization unavailable',
                'ไม่สามารถดำเนินการได้ในขณะนี้',
            );
        }

        $profileId = $request->route('profile');

        $resource = is_string($profileId) && $profileId !== ''
            ? ResourceRef::forProfile($workspace->id, $profileId)
            : ResourceRef::forWorkspace($workspace->id);

        $decision = $this->authorizer->authorize($request->user(), $permission, $resource);

        if (! $decision->allowed()) {
            return ProblemDetails::fromDecision($decision, $request);
        }

        return $next($request);
    }
}
