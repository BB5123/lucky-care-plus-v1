<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProblemDetails;
use App\Modules\Authz\Authorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-capability declaration layer (CR-005 / ABD-019).
 *
 * Unlike {@see EnsureAuthorized}, this middleware has no `{workspace}` and
 * does not require `workspace.context`: platform data is not tenant-scoped.
 * The actor is resolved by `auth:sanctum` and the decision is delegated to
 * {@see Authorizer::authorizePlatform()}, which consults only the explicit
 * `users.is_platform_admin` flag.
 *
 * Usage: `->middleware('authorize.platform:knowledge.source.read')`.
 */
final class EnsurePlatformAuthorized
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $context = [];

        $sourceId = $request->route('id');

        if (is_string($sourceId) && $sourceId !== '') {
            $context['source_id'] = $sourceId;
        }

        // The audit row records the reviewer that will be validated by the
        // FormRequest. Only a plausible value is copied into the append-only
        // ledger; invalid input is rejected by validation before any source is
        // read or written.
        $reviewer = $request->input('reviewer');

        if (is_string($reviewer)) {
            $reviewer = trim($reviewer);

            if (mb_strlen($reviewer) >= 3 && mb_strlen($reviewer) <= 100) {
                $context['reviewer'] = $reviewer;
            }
        }

        $decision = $this->authorizer->authorizePlatform(
            $request->user(),
            $permission,
            $context,
        );

        if (! $decision->allowed()) {
            return ProblemDetails::fromDecision($decision, $request);
        }

        return $next($request);
    }
}
