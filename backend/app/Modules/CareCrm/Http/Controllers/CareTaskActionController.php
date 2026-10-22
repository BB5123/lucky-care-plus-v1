<?php

namespace App\Modules\CareCrm\Http\Controllers;

use App\Modules\CareCrm\Application\CareTaskService;
use App\Modules\CareCrm\Application\CareTaskTransitionService;
use App\Modules\CareCrm\Domain\VersionHeader;
use App\Modules\CareCrm\Http\Requests\AssignTaskRequest;
use App\Modules\CareCrm\Http\Requests\BlockTaskRequest;
use App\Modules\CareCrm\Http\Requests\CancelTaskRequest;
use App\Modules\CareCrm\Http\Requests\CompleteTaskRequest;
use App\Modules\CareCrm\Http\Requests\DeclineTaskRequest;
use App\Modules\CareCrm\Http\Requests\RejectTaskRequest;
use App\Modules\CareCrm\Http\Requests\ReopenTaskRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One endpoint per transition. The domain service owns every rule; this class
 * only reads the trusted workspace, the If-Match version and the validated
 * body, then returns the new DTO.
 */
final class CareTaskActionController extends CareCrmController
{
    public function __construct(
        private readonly CareTaskTransitionService $transitions,
        private readonly CareTaskService $tasks,
    ) {}

    public function assign(AssignTaskRequest $request): JsonResponse
    {
        return $this->respond($request, 'assign', $request->validated());
    }

    public function accept(Request $request): JsonResponse
    {
        return $this->respond($request, 'accept');
    }

    public function decline(DeclineTaskRequest $request): JsonResponse
    {
        return $this->respond($request, 'decline', $request->validated());
    }

    public function start(Request $request): JsonResponse
    {
        return $this->respond($request, 'start');
    }

    public function complete(CompleteTaskRequest $request): JsonResponse
    {
        return $this->respond($request, 'complete', $request->validated());
    }

    public function verify(Request $request): JsonResponse
    {
        return $this->respond($request, 'verify');
    }

    public function reject(RejectTaskRequest $request): JsonResponse
    {
        return $this->respond($request, 'reject', $request->validated());
    }

    public function reopen(ReopenTaskRequest $request): JsonResponse
    {
        return $this->respond($request, 'reopen', $request->validated());
    }

    public function cancel(CancelTaskRequest $request): JsonResponse
    {
        return $this->respond($request, 'cancel', $request->validated());
    }

    public function block(BlockTaskRequest $request): JsonResponse
    {
        return $this->respond($request, 'block', $request->validated());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function respond(Request $request, string $action, array $input = []): JsonResponse
    {
        $task = $this->transitions->apply(
            $this->workspace($request),
            (string) $request->route('task'),
            $this->actor($request),
            $action,
            VersionHeader::require($request),
            $input,
        );

        return response()->json([
            'data' => $this->present($request, $task, $this->tasks),
        ]);
    }
}
