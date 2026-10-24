<?php

namespace App\Modules\CareCrm\Http\Controllers;

use App\Modules\CareCrm\Application\CareTaskService;
use App\Modules\CareCrm\Http\Requests\CreateTaskEvidenceRequest;
use App\Modules\CareCrm\Http\Resources\TaskEvidenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evidence read/create. Reading evidence requires the separate
 * `task_evidence:read` scope (CR-004): it may contain health data, so a bare
 * task reader or assignee must not receive it (F-04 / CRM-006). Attaching
 * evidence stays on `tasks:create` because it is part of doing the task.
 */
final class TaskEvidenceController extends CareCrmController
{
    public function __construct(private readonly CareTaskService $tasks) {}

    public function index(Request $request): JsonResponse
    {
        $task = $this->tasks->find($this->workspace($request), (string) $request->route('task'));
        $evidence = $this->tasks->listEvidence($task, $this->actor($request));

        return response()->json([
            'data' => TaskEvidenceResource::collection($evidence)->resolve($request),
        ]);
    }

    public function store(CreateTaskEvidenceRequest $request): JsonResponse
    {
        $task = $this->tasks->find($this->workspace($request), (string) $request->route('task'));

        $evidence = $this->tasks->addEvidence(
            $this->workspace($request),
            $this->actor($request),
            $task,
            $request->validated(),
        );

        return response()->json(
            ['data' => (new TaskEvidenceResource($evidence))->resolve($request)],
            201,
        );
    }
}
