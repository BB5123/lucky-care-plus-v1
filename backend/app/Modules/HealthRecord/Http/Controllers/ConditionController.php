<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Models\User;
use App\Modules\HealthRecord\Application\HealthConditionService;
use App\Modules\HealthRecord\Domain\VersionHeader;
use App\Modules\HealthRecord\Http\Requests\CreateConditionRequest;
use App\Modules\HealthRecord\Http\Requests\ListConditionsRequest;
use App\Modules\HealthRecord\Http\Requests\UpdateConditionRequest;
use App\Modules\HealthRecord\Http\Resources\ConditionResource;
use Illuminate\Http\JsonResponse;

/**
 * Permitted conditions for one profile.
 */
final class ConditionController extends HealthVaultController
{
    public function __construct(private readonly HealthConditionService $conditions) {}

    public function index(ListConditionsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = $this->conditions->paginate(
            $this->profile($request),
            $data['status'] ?? null,
            $this->limit($data),
            $data['cursor'] ?? null,
        );

        return response()->json([
            'data' => ConditionResource::collection($page->items())->resolve($request),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function store(CreateConditionRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $condition = $this->conditions->create(
            $this->profile($request),
            $actor,
            $request->validated(),
        );

        return response()->json(
            ['data' => (new ConditionResource($condition))->resolve($request)],
            201,
        );
    }

    public function update(UpdateConditionRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $this->conditions->correct(
            $this->profile($request),
            (string) $request->route('condition'),
            VersionHeader::require($request),
            $actor,
            $request->validated(),
        );

        return response()->json([
            'data' => (new ConditionResource($updated))->resolve($request),
        ]);
    }
}
