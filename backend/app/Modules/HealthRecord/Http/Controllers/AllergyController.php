<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Models\User;
use App\Modules\HealthRecord\Application\AllergyService;
use App\Modules\HealthRecord\Domain\VersionHeader;
use App\Modules\HealthRecord\Http\Requests\CreateAllergyRequest;
use App\Modules\HealthRecord\Http\Requests\ListAllergiesRequest;
use App\Modules\HealthRecord\Http\Requests\UpdateAllergyRequest;
use App\Modules\HealthRecord\Http\Resources\AllergyResource;
use Illuminate\Http\JsonResponse;

/**
 * Allergies for one profile.
 *
 * The catalog has no `allergy.create`; both POST (first version) and PATCH
 * (later versions) are authorized by `allergy.update`.
 */
final class AllergyController extends HealthVaultController
{
    public function __construct(private readonly AllergyService $allergies) {}

    public function index(ListAllergiesRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = $this->allergies->paginate(
            $this->profile($request),
            $data['severity'] ?? null,
            $this->limit($data),
            $data['cursor'] ?? null,
        );

        return response()->json([
            'data' => AllergyResource::collection($page->items())->resolve($request),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function store(CreateAllergyRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $allergy = $this->allergies->create(
            $this->profile($request),
            $actor,
            $request->validated(),
        );

        return response()->json(
            ['data' => (new AllergyResource($allergy))->resolve($request)],
            201,
        );
    }

    public function update(UpdateAllergyRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $this->allergies->update(
            $this->profile($request),
            (string) $request->route('allergy'),
            VersionHeader::require($request),
            $actor,
            $request->validated(),
        );

        return response()->json([
            'data' => (new AllergyResource($updated))->resolve($request),
        ]);
    }
}
