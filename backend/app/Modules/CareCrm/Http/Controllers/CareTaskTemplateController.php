<?php

namespace App\Modules\CareCrm\Http\Controllers;

use App\Modules\CareCrm\Application\CareTaskTemplateService;
use App\Modules\CareCrm\Domain\CareTaskTemplateNotFoundException;
use App\Modules\CareCrm\Domain\VersionHeader;
use App\Modules\CareCrm\Http\Requests\CreateCareTaskTemplateRequest;
use App\Modules\CareCrm\Http\Requests\CreateTaskTemplateExceptionRequest;
use App\Modules\CareCrm\Http\Requests\UpdateCareTaskTemplateRequest;
use App\Modules\CareCrm\Http\Resources\CareTaskTemplateExceptionResource;
use App\Modules\CareCrm\Http\Resources\CareTaskTemplateResource;
use App\Modules\CareCrm\Models\CareTaskTemplate;
use App\Modules\HealthRecord\Models\HealthProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recurring care-task templates (P2.1).
 *
 * Route middleware has already proved the required `care_task.template.*`
 * permission for this profile. The controller resolves trusted route context, reads
 * `If-Match` for every mutation, and delegates all scheduling rules to
 * {@see CareTaskTemplateService}.
 */
final class CareTaskTemplateController extends CareCrmController
{
    public function __construct(private readonly CareTaskTemplateService $templates) {}

    public function index(Request $request): JsonResponse
    {
        $templates = $this->templates->listForProfile($this->profile($request));

        return response()->json([
            'data' => CareTaskTemplateResource::collection($templates)->resolve($request),
        ]);
    }

    public function store(CreateCareTaskTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create(
            $this->profile($request),
            $this->actor($request),
            $request->validated(),
        );

        return $this->respond($request, $template, 201);
    }

    public function show(Request $request): JsonResponse
    {
        $template = $this->templates->find(
            $this->profile($request),
            (string) $request->route('template'),
        );

        return $this->respond($request, $template);
    }

    public function update(UpdateCareTaskTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->update(
            $this->profile($request),
            (string) $request->route('template'),
            VersionHeader::require($request),
            $this->actor($request),
            $request->validated(),
        );

        return $this->respond($request, $template);
    }

    public function deactivate(Request $request): JsonResponse
    {
        $template = $this->templates->deactivate(
            $this->profile($request),
            (string) $request->route('template'),
            VersionHeader::require($request),
            $this->actor($request),
        );

        return $this->respond($request, $template);
    }

    public function addException(CreateTaskTemplateExceptionRequest $request): JsonResponse
    {
        $exception = $this->templates->addException(
            $this->profile($request),
            (string) $request->route('template'),
            VersionHeader::require($request),
            $this->actor($request),
            $request->validated(),
        );

        return response()->json(
            ['data' => (new CareTaskTemplateExceptionResource($exception))->resolve($request)],
            201,
        );
    }

    private function respond(Request $request, CareTaskTemplate $template, int $status = 200): JsonResponse
    {
        return response()->json(
            ['data' => (new CareTaskTemplateResource($template))->resolve($request)],
            $status,
        );
    }

    private function profile(Request $request): HealthProfile
    {
        $workspace = $this->workspace($request);
        $profileId = $request->route('profile');

        $profile = is_string($profileId)
            ? HealthProfile::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($profileId)
                ->first()
            : null;

        if (! $profile instanceof HealthProfile) {
            throw new CareTaskTemplateNotFoundException;
        }

        return $profile;
    }
}
