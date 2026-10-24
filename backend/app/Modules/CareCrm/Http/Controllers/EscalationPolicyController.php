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
 * Route middleware proves `escalation_policy.read` for GET and
 * `escalation_policy.update` for both mutations (CR-003 / baseline v1.2.0).
 * Escalation behaviour is its own authority, not a workspace-settings action.
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
