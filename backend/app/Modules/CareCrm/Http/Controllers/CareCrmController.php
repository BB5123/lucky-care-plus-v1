<?php

namespace App\Modules\CareCrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CareCrm\Application\CareTaskService;
use App\Modules\CareCrm\Domain\CareTaskNotFoundException;
use App\Modules\CareCrm\Http\Resources\CareTaskCardResource;
use App\Modules\CareCrm\Http\Resources\CareTaskResource;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Shared, trusted context for care-task controllers.
 *
 * `workspace` comes from the `workspace.context` middleware; the task id comes
 * from the route and is always re-scoped by the service. Tenant/workspace/
 * profile identity is never read from a request body (N-01).
 */
abstract class CareCrmController extends Controller
{
    protected function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        if (! $workspace instanceof Workspace) {
            throw new CareTaskNotFoundException;
        }

        return $workspace;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new CareTaskNotFoundException;
        }

        return $actor;
    }

    /**
     * Choose the need-to-know card or the full DTO from the actor's scope.
     *
     * `$fullProfileIds` is the set a list endpoint already computed for the
     * page. Passing it avoids one owner/grant lookup per row; a single-task
     * endpoint leaves it null and the service answers for that one task.
     *
     * @param  list<string>|null  $fullProfileIds
     * @return array<string, mixed>
     */
    protected function present(
        Request $request,
        CareTask $task,
        CareTaskService $tasks,
        ?array $fullProfileIds = null,
    ): array {
        $canReadFull = $fullProfileIds === null
            ? $tasks->canReadFull($this->actor($request), $task)
            : in_array((string) $task->profile_id, $fullProfileIds, true);

        if ($canReadFull) {
            return (new CareTaskResource($task))->resolve($request);
        }

        return (new CareTaskCardResource($task))->resolve($request);
    }

    /**
     * @return array{next_cursor: ?string, limit: int}
     */
    protected function paginationMeta(CursorPaginator $paginator): array
    {
        return [
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'limit' => $paginator->perPage(),
        ];
    }
}
