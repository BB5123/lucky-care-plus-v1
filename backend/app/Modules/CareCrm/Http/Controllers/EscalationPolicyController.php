<?php

namespace App\Modules\CareCrm\Http\Controllers;

use App\Modules\CareCrm\Application\EscalationPolicyService;
use App\Modules\CareCrm\Http\Requests\ReplaceEscalationPoliciesRequest;
use App\Modules\CareCrm\Http\Resources\EscalationPolicyResource;
use App\Modules\CareCrm\Models\EscalationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Workspace escalation ladder (P2.2).
 *
 * Route middleware proves `workspace.read` for GET and `workspace.update` for
 * both mutations. This is a workspace setting, not a health-record operation,
 * so it consumes no new permission id (the frozen catalog has no
 * escalation-specific id).
 */
final class EscalationPolicyController extends CareCrmController
{
    public function __construct(private readonly EscalationPolicyService $policies) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond($request, $this->policies->listFor($this->workspace($request)));
    }

    public function replace(ReplaceEscalationPoliciesRequest $request): JsonResponse
    {
        $ladder = $this->policies->replace(
            $this->workspace($request),
            $request->validated()['policies'],
        );

        return $this->respond($request, $ladder);
    }

    public function defaults(Request $request): JsonResponse
    {
        return $this->respond($request, $this->policies->applyDefaults($this->workspace($request)));
    }

    /**
     * @param  Collection<int, EscalationPolicy>  $ladder
     */
    private function respond(Request $request, Collection $ladder): JsonResponse
    {
        return response()->json([
            'data' => EscalationPolicyResource::collection($ladder)->resolve($request),
        ]);
    }
}
