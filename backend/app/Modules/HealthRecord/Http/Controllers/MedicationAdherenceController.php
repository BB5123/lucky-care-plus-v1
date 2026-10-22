<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Modules\HealthRecord\Application\MedicationAdherenceService;
use App\Modules\HealthRecord\Http\Requests\MedicationAdherenceRequest;
use Illuminate\Http\JsonResponse;

/**
 * 7-day (configurable) adherence per plan.
 *
 * Authorized by `medication_record.read`: adherence is an aggregate over the
 * reported events, not a new class of data access (F-06).
 */
final class MedicationAdherenceController extends HealthVaultController
{
    public function __construct(private readonly MedicationAdherenceService $adherence) {}

    public function index(MedicationAdherenceRequest $request): JsonResponse
    {
        $days = (int) ($request->validated()['days'] ?? MedicationAdherenceService::DEFAULT_DAYS);

        $result = $this->adherence->compute($this->profile($request), $days);

        return response()->json([
            'data' => $result['plans'],
            'meta' => [
                'days' => $result['days'],
                'from' => $result['from'],
                'to' => $result['to'],
            ],
        ]);
    }
}
