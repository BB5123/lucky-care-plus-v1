<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Models\User;
use App\Modules\HealthRecord\Application\DoctorBriefBuilder;
use App\Modules\HealthRecord\Application\HealthReportService;
use App\Modules\HealthRecord\Domain\HealthReportNotFoundException;
use App\Modules\HealthRecord\Http\Requests\CreateHealthReportRequest;
use App\Modules\HealthRecord\Http\Resources\HealthReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Doctor Brief snapshots (Phase 2.3).
 *
 * Every route already passed `authorize.permission:health_report.*`; the
 * controller only resolves trusted route context and delegates. Section-level
 * visibility is enforced inside {@see DoctorBriefBuilder}.
 */
final class HealthReportController extends HealthVaultController
{
    public function __construct(private readonly HealthReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $reports = $this->reports->list($this->profile($request));

        return response()->json([
            'data' => HealthReportResource::collection($reports)->resolve($request),
        ]);
    }

    public function store(CreateHealthReportRequest $request): JsonResponse
    {
        $report = $this->reports->create(
            $this->workspace($request),
            $this->profile($request),
            $this->actor($request),
            $request->validated(),
        );

        return response()->json([
            'data' => (new HealthReportResource($report, true))->resolve($request),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $report = $this->reports->find(
            $this->profile($request),
            (string) $request->route('report'),
        );

        return response()->json([
            'data' => (new HealthReportResource($report, true))->resolve($request),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new HealthReportNotFoundException;
        }

        return $actor;
    }
}
