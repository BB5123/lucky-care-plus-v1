<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\HealthRecord\Application\DataExportService;
use App\Modules\HealthRecord\Domain\DataExportNotFoundException;
use App\Modules\HealthRecord\Domain\ExportExpiredException;
use App\Modules\HealthRecord\Domain\ExportNotPermittedException;
use App\Modules\HealthRecord\Http\Resources\DataExportResource;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The PDPA data-export surface.
 *
 * Every route declares its frozen `data_export.*` permission; the Authorizer
 * has already proved membership, role, profile scope/grant and the
 * `data_export` consent before a method runs. Workspace and profile identity
 * come only from the resolved route context (N-01).
 */
final class DataExportController extends Controller
{
    public function __construct(private readonly DataExportService $exports) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'format' => ['nullable', 'string', 'in:json'],
        ]);

        $export = $this->exports->request(
            $this->workspace($request),
            $this->profile($request),
            $this->actor($request),
        );

        return response()->json(
            ['data' => (new DataExportResource($export))->resolve($request)],
            202,
        );
    }

    public function show(Request $request): JsonResponse
    {
        $export = $this->exports->show(
            $this->workspace($request),
            $this->profile($request),
            (string) $request->route('export'),
        );

        return response()->json(
            ['data' => (new DataExportResource($export))->resolve($request)],
        );
    }

    public function link(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            abort_unless($user instanceof User, 401);

            $link = $this->exports->link(
                $this->workspace($request),
                $this->profile($request),
                (string) $request->route('export'),
                $user,
            );
        } catch (ExportExpiredException|ExportNotPermittedException $exception) {
            // The service records the `ready -> expired` or `ready -> revoked`
            // transition before it throws. Returning the response (instead of
            // letting the exception escape) keeps that state change inside
            // this request's committed transaction; a thrown exception would
            // roll it back.
            return $exception->toResponse($request);
        }

        return response()->json([
            'data' => [
                'url' => $link['url'],
                'expires_at' => $link['expires_at']->toIso8601String(),
            ],
        ]);
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        if (! $workspace instanceof Workspace) {
            throw new DataExportNotFoundException;
        }

        return $workspace;
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
            throw new DataExportNotFoundException;
        }

        return $profile;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new DataExportNotFoundException;
        }

        return $actor;
    }
}
