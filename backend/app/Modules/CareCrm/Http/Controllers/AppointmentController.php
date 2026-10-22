<?php

namespace App\Modules\CareCrm\Http\Controllers;

use App\Modules\CareCrm\Application\AppointmentService;
use App\Modules\CareCrm\Domain\AppointmentNotFoundException;
use App\Modules\CareCrm\Domain\VersionHeader;
use App\Modules\CareCrm\Http\Requests\AttendedAppointmentRequest;
use App\Modules\CareCrm\Http\Requests\CancelAppointmentRequest;
use App\Modules\CareCrm\Http\Requests\CreateAppointmentRequest;
use App\Modules\CareCrm\Http\Requests\ListAppointmentsRequest;
use App\Modules\CareCrm\Http\Requests\UpdateAppointmentDocumentsRequest;
use App\Modules\CareCrm\Http\Requests\UpdateAppointmentRequest;
use App\Modules\CareCrm\Http\Resources\AppointmentResource;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\HealthRecord\Models\HealthProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appointment endpoints (Phase 1.4).
 *
 * Every route has already been authorized by `EnsureAuthorized` with a frozen
 * `appointment.*` permission ID. This controller only resolves trusted route
 * context (workspace/profile/appointment), reads `If-Match`, and delegates the
 * domain rules to {@see AppointmentService}.
 */
final class AppointmentController extends CareCrmController
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function index(ListAppointmentsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = $this->appointments->paginate(
            $this->profile($request),
            [
                'status' => $data['status'] ?? null,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
                'upcoming' => $data['upcoming'] ?? null,
            ],
            (int) ($data['limit'] ?? 20),
            $data['cursor'] ?? null,
        );

        return response()->json([
            'data' => AppointmentResource::collection($page->items())->resolve($request),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function store(CreateAppointmentRequest $request): JsonResponse
    {
        $appointment = $this->appointments->create(
            $this->profile($request),
            $this->actor($request),
            $request->validated(),
        );

        return response()->json(
            ['data' => (new AppointmentResource($appointment))->resolve($request)],
            201,
        );
    }

    public function show(Request $request): JsonResponse
    {
        $appointment = $this->appointments->find(
            $this->profile($request),
            (string) $request->route('appointment'),
        );

        return response()->json([
            'data' => (new AppointmentResource($appointment))->resolve($request),
        ]);
    }

    public function update(UpdateAppointmentRequest $request): JsonResponse
    {
        $appointment = $this->appointments->update(
            $this->profile($request),
            (string) $request->route('appointment'),
            VersionHeader::require($request),
            $this->actor($request),
            $request->validated(),
        );

        return $this->respond($request, $appointment);
    }

    public function cancel(CancelAppointmentRequest $request): JsonResponse
    {
        $appointment = $this->appointments->cancel(
            $this->profile($request),
            (string) $request->route('appointment'),
            VersionHeader::require($request),
            $this->actor($request),
            (string) $request->validated('reason'),
        );

        return $this->respond($request, $appointment);
    }

    public function attended(AttendedAppointmentRequest $request): JsonResponse
    {
        $appointment = $this->appointments->attended(
            $this->workspace($request),
            $this->profile($request),
            (string) $request->route('appointment'),
            VersionHeader::require($request),
            $this->actor($request),
            $request->validated('attended_at'),
        );

        return $this->respond($request, $appointment);
    }

    public function documents(UpdateAppointmentDocumentsRequest $request): JsonResponse
    {
        $appointment = $this->appointments->updateDocuments(
            $this->profile($request),
            (string) $request->route('appointment'),
            VersionHeader::require($request),
            $this->actor($request),
            $request->validated(),
        );

        return $this->respond($request, $appointment);
    }

    private function respond(Request $request, Appointment $appointment): JsonResponse
    {
        return response()->json([
            'data' => (new AppointmentResource($appointment))->resolve($request),
        ]);
    }

    private function profile(Request $request): HealthProfile
    {
        $workspace = $this->workspace($request);
        $profileId = $request->route('profile');

        $profile = is_string($profileId)
            ? HealthProfile::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($profileId)
                ->first()
            : null;

        if (! $profile instanceof HealthProfile) {
            throw new AppointmentNotFoundException;
        }

        return $profile;
    }
}
