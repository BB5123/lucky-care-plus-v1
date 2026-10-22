<?php

namespace App\Modules\CareCrm\Http\Controllers;

use App\Modules\CareCrm\Application\CareTaskService;
use App\Modules\CareCrm\Http\Requests\CreateCareTaskRequest;
use App\Modules\CareCrm\Http\Requests\ListCareTasksRequest;
use App\Modules\CareCrm\Http\Resources\TaskEventResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Care-task read/create endpoints. Mutations live in the action controller.
 */
final class CareTaskController extends CareCrmController
{
    public function __construct(private readonly CareTaskService $tasks) {}

    public function index(ListCareTasksRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = $this->tasks->paginate(
            $this->workspace($request),
            $this->actor($request),
            [
                'status' => $data['status'] ?? null,
                'tier' => $data['tier'] ?? null,
                'type' => $data['type'] ?? null,
                'assignee' => $data['assignee'] ?? null,
                'due_before' => $data['due_before'] ?? null,
                'overdue' => $data['overdue'] ?? null,
            ],
            (int) ($data['limit'] ?? 20),
            $data['cursor'] ?? null,
        );

        return response()->json([
            'data' => array_map(
                fn ($task): array => $this->present($request, $task, $this->tasks),
                $page->items(),
            ),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function store(CreateCareTaskRequest $request): JsonResponse
    {
        $idempotencyKey = $this->idempotencyKey($request);

        [$task, $created] = $this->tasks->create(
            $this->workspace($request),
            $this->actor($request),
            $request->validated(),
            $idempotencyKey,
        );

        return response()->json(
            ['data' => $this->present($request, $task, $this->tasks)],
            $created ? 201 : 200,
        );
    }

    public function show(Request $request): JsonResponse
    {
        $task = $this->tasks->find($this->workspace($request), (string) $request->route('task'));
        $this->tasks->assertVisible($task, $this->actor($request));

        return response()->json([
            'data' => $this->present($request, $task, $this->tasks),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $task = $this->tasks->find($this->workspace($request), (string) $request->route('task'));
        $events = $this->tasks->listEvents($task, $this->actor($request));

        return response()->json([
            'data' => TaskEventResource::collection($events)->resolve($request),
        ]);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || trim($key) === '') {
            return null;
        }

        $key = trim($key);

        if (strlen($key) > 255) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['The idempotency key may not be longer than 255 characters.'],
            ]);
        }

        return $key;
    }
}
