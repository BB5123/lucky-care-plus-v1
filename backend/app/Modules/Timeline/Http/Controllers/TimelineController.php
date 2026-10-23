<?php

namespace App\Modules\Timeline\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Timeline\Application\TimelineAssembler;
use App\Modules\Timeline\Http\Requests\ListTimelineRequest;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * `GET /workspaces/{workspace}/profiles/{profile}/timeline`.
 *
 * Route middleware proves `timeline.read` and resolves the workspace/profile
 * server-side. The assembler then re-proves the owning scope of every entry,
 * so this controller never widens access to the scopes the timeline composes
 * (F-06 / CRM-013).
 */
final class TimelineController extends Controller
{
    public function __invoke(ListTimelineRequest $request, TimelineAssembler $assembler): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $actor = $request->user();
        $profileId = $request->route('profile');

        if (
            ! $workspace instanceof Workspace
            || ! $actor instanceof User
            || ! is_string($profileId)
        ) {
            return $this->notFound($request);
        }

        $profile = HealthProfile::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->whereKey($profileId)
            ->first();

        if (! $profile instanceof HealthProfile) {
            return $this->notFound($request);
        }

        $data = $request->validated();

        return response()->json($assembler->page(
            $request,
            $workspace,
            $actor,
            $profile,
            $request->types(),
            $data['from'] ?? null,
            $data['to'] ?? null,
            (int) ($data['limit'] ?? 20),
            $data['cursor'] ?? null,
        ));
    }

    private function notFound(ListTimelineRequest $request): JsonResponse
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
