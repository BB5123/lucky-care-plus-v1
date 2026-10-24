<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Models\User;
use App\Modules\HealthRecord\Application\HealthReportService;
use App\Modules\HealthRecord\Application\ReportShareService;
use App\Modules\HealthRecord\Domain\HealthReportNotFoundException;
use App\Modules\HealthRecord\Http\Requests\ShareHealthReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Creating and revoking Doctor Brief capability links.
 *
 * Both actions are gated by `health_report.export` (AP-004: export is not the
 * same permission as read) and by the Authorizer's `data_export` consent check.
 */
final class ReportShareController extends HealthVaultController
{
    public function __construct(
        private readonly HealthReportService $reports,
        private readonly ReportShareService $shares,
    ) {}

    public function store(ShareHealthReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->shares->share(
            $this->workspace($request),
            $this->reports->find($this->profile($request), (string) $request->route('report')),
            $this->actor($request),
            isset($validated['expires_in_minutes']) ? (int) $validated['expires_in_minutes'] : null,
            $validated['recipient_label'] ?? null,
        );

        return response()->json([
            'data' => [
                'id' => $result['share']->id,
                'url' => $result['url'],
                // Returned exactly once; only its digest is persisted.
                'token' => $result['token'],
                'expires_at' => $result['share']->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        $this->shares->revoke(
            $this->workspace($request),
            $this->reports->find($this->profile($request), (string) $request->route('report')),
            (string) $request->route('share'),
            $this->actor($request),
        );

        return response()->noContent();
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
