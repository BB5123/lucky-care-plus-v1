<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Models\User;
use App\Modules\HealthRecord\Application\HealthMeasurementService;
use App\Modules\HealthRecord\Domain\VersionHeader;
use App\Modules\HealthRecord\Http\Requests\CreateMeasurementRequest;
use App\Modules\HealthRecord\Http\Requests\ListMeasurementsRequest;
use App\Modules\HealthRecord\Http\Requests\TrendMeasurementsRequest;
use App\Modules\HealthRecord\Http\Requests\UpdateMeasurementRequest;
use App\Modules\HealthRecord\Http\Resources\MeasurementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Measurements — typed vital readings for one profile.
 *
 * Every action relies on `authorize.permission:health_measurement.*`; the
 * controller never compares roles or decides scope itself.
 */
final class MeasurementController extends HealthVaultController
{
    public function __construct(private readonly HealthMeasurementService $measurements) {}

    public function index(ListMeasurementsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = $this->measurements->paginate(
            $this->profile($request),
            [
                'type' => $data['type'] ?? null,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ],
            $this->limit($data),
            $data['cursor'] ?? null,
        );

        return response()->json([
            'data' => MeasurementResource::collection($page->items())->resolve($request),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function store(CreateMeasurementRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $measurement = $this->measurements->create(
            $this->profile($request),
            $actor,
            $request->validated(),
        );

        return response()->json(
            ['data' => (new MeasurementResource($measurement))->resolve($request)],
            201,
        );
    }

    public function update(UpdateMeasurementRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $this->measurements->correct(
            $this->profile($request),
            (string) $request->route('measurement'),
            VersionHeader::require($request),
            $actor,
            $request->validated(),
        );

        return response()->json([
            'data' => (new MeasurementResource($updated))->resolve($request),
        ]);
    }

    public function destroy(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->measurements->delete(
            $this->profile($request),
            (string) $request->route('measurement'),
            VersionHeader::require($request),
            $actor,
        );

        return response()->noContent();
    }

    public function trend(TrendMeasurementsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : now()->subDays(29)->startOfDay();

        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->endOfDay()
            : now();

        return response()->json([
            'data' => $this->measurements->trend(
                $this->profile($request),
                (string) $data['type'],
                $from,
                $to,
            ),
            'meta' => [
                'type' => $data['type'],
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'bucket' => 'day',
            ],
        ]);
    }
}
