<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Models\User;
use App\Modules\HealthRecord\Application\MedicationPlanService;
use App\Modules\HealthRecord\Domain\VersionHeader;
use App\Modules\HealthRecord\Http\Requests\CreateMedicationPlanRequest;
use App\Modules\HealthRecord\Http\Requests\DiscontinueMedicationPlanRequest;
use App\Modules\HealthRecord\Http\Requests\ListMedicationPlansRequest;
use App\Modules\HealthRecord\Http\Requests\UpdateMedicationPlanRequest;
use App\Modules\HealthRecord\Http\Resources\MedicationPlanResource;
use Illuminate\Http\JsonResponse;

/**
 * Medication plans — the schedule a reported dose belongs to.
 *
 * Every action declares its frozen permission ID on the route
 * (`medication_plan.*`); the controller never decides authorization itself.
 */
final class MedicationPlanController extends HealthVaultController
{
    public function __construct(private readonly MedicationPlanService $plans) {}

    public function index(ListMedicationPlansRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = $this->plans->paginate(
            $this->profile($request),
            $data['status'] ?? null,
            $this->limit($data),
            $data['cursor'] ?? null,
        );

        return response()->json([
            'data' => MedicationPlanResource::collection($page->items())->resolve($request),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function store(CreateMedicationPlanRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $plan = $this->plans->create(
            $this->profile($request),
            $actor,
            $request->validated(),
        );

        return response()->json(
            ['data' => (new MedicationPlanResource($plan))->resolve($request)],
            201,
        );
    }

    public function update(UpdateMedicationPlanRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $plan = $this->plans->update(
            $this->profile($request),
            (string) $request->route('plan'),
            VersionHeader::require($request),
            $actor,
            $request->validated(),
        );

        return response()->json([
            'data' => (new MedicationPlanResource($plan))->resolve($request),
        ]);
    }

    public function discontinue(DiscontinueMedicationPlanRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $plan = $this->plans->discontinue(
            $this->profile($request),
            (string) $request->route('plan'),
            VersionHeader::require($request),
            $actor,
            $request->validated()['reason'],
        );

        return response()->json([
            'data' => (new MedicationPlanResource($plan))->resolve($request),
        ]);
    }
}
