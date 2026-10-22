<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Models\User;
use App\Modules\HealthRecord\Application\MedicationRecordService;
use App\Modules\HealthRecord\Domain\VersionHeader;
use App\Modules\HealthRecord\Http\Requests\CreateMedicationRecordRequest;
use App\Modules\HealthRecord\Http\Requests\ListMedicationRecordsRequest;
use App\Modules\HealthRecord\Http\Requests\UpdateMedicationRecordRequest;
use App\Modules\HealthRecord\Http\Resources\MedicationRecordResource;
use Illuminate\Http\JsonResponse;

/**
 * Reported medication events (taken / skipped / missed).
 *
 * CAUTION: a `taken` row is self-reported, not clinical proof of ingestion
 * (`.ai/05-CARE-COORDINATION.md` §3). The HTTP path is always a human reporter,
 * so the domain layer stores such rows with `source = manual`.
 */
final class MedicationRecordController extends HealthVaultController
{
    public function __construct(private readonly MedicationRecordService $records) {}

    public function index(ListMedicationRecordsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = $this->records->paginate(
            $this->profile($request),
            $data['plan_id'] ?? null,
            $data['status'] ?? null,
            $this->limit($data),
            $data['cursor'] ?? null,
        );

        return response()->json([
            'data' => MedicationRecordResource::collection($page->items())->resolve($request),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function store(CreateMedicationRecordRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $record = $this->records->create(
            $this->profile($request),
            $actor,
            $request->validated(),
        );

        return response()->json(
            ['data' => (new MedicationRecordResource($record))->resolve($request)],
            201,
        );
    }

    public function update(UpdateMedicationRecordRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $record = $this->records->correct(
            $this->profile($request),
            (string) $request->route('record'),
            VersionHeader::require($request),
            $actor,
            $request->validated(),
        );

        return response()->json([
            'data' => (new MedicationRecordResource($record))->resolve($request),
        ]);
    }
}
