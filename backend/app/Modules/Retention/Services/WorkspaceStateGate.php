<?php

namespace App\Modules\Retention\Services;

use App\Http\Responses\ProblemDetails;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Applies the account's retention state to every workspace-scoped request (P2).
 *
 * This is the enforcement half of RETENTION-MODEL.md section 2. Without it the
 * states would be labels: a "read_only" account would still accept writes and
 * an "archived" account would still serve health data.
 *
 *   active             everything (the gate is a no-op)
 *   read_only          safe methods (GET/HEAD) and export; every write is 409
 *   archived           export + the workspace metadata read; everything else 409
 *   scheduled_deletion export + the workspace metadata read; everything else 409
 *   deleted            410 for every request
 *
 * The gate runs inside `ResolveWorkspaceContext`, after membership is proven and
 * before any controller or authorizer decision. It is fail-closed: a request
 * whose route declares no permission is only allowed in `active` (or by the
 * safe-method rule in `read_only`).
 *
 * Export is deliberately allowed in every non-deleted state - the privacy notice
 * promises "export is free in every plan, at every state", and a lapsed account
 * must be able to take its data out.
 */
final class WorkspaceStateGate
{
    /**
     * Permissions that stay usable in a non-active state.
     *
     * @var list<string>
     */
    private const EXPORT_PERMISSIONS = ['data_export.request', 'data_export.download'];

    /**
     * The metadata read that tells a client which state the account is in, so it
     * can offer restore or export. It carries no health data.
     *
     * @var list<string>
     */
    private const METADATA_PERMISSIONS = ['workspace.read'];

    public function __construct(private readonly SecurityAuditLogger $audit) {}

    public function check(Workspace $workspace, Request $request): ?JsonResponse
    {
        $state = $this->state($workspace);

        if ($state === RetentionState::Active) {
            return null;
        }

        if ($state === RetentionState::Deleted) {
            return $this->deny($workspace, $request, 410, 'workspace_deleted');
        }

        $permissions = $this->routePermissions($request);
        $export = array_intersect($permissions, self::EXPORT_PERMISSIONS) !== [];
        $metadata = array_intersect($permissions, self::METADATA_PERMISSIONS) !== [];

        if ($state === RetentionState::ReadOnly) {
            // Read and export are free; everything that writes is refused until
            // the account is restored.
            if ($request->isMethodSafe() || $export) {
                return null;
            }

            return $this->deny($workspace, $request, 409, 'workspace_read_only');
        }

        // archived / scheduled_deletion: the record is not loaded into the app.
        // Only export and the state read above are answered.
        if ($export || ($request->isMethodSafe() && $metadata)) {
            return null;
        }

        return $this->deny(
            $workspace,
            $request,
            409,
            $state === RetentionState::Archived ? 'workspace_archived' : 'workspace_scheduled_deletion',
        );
    }

    private function state(Workspace $workspace): RetentionState
    {
        $state = $workspace->retention_state;

        if ($state instanceof RetentionState) {
            return $state;
        }

        if ($state === null || $state === '') {
            throw new \LogicException('Workspace has no retention state loaded; refusing the request (F-09).');
        }

        return RetentionState::from((string) $state);
    }

    /**
     * The permission IDs a route declares through `authorize.permission:<id>`.
     *
     * Reading the route's own middleware is how the gate distinguishes an
     * export (allowed) from any other POST (blocked) without a second list of
     * URLs that could drift from the route file.
     *
     * @return list<string>
     */
    private function routePermissions(Request $request): array
    {
        $route = $request->route();

        if ($route === null) {
            return [];
        }

        $permissions = [];

        foreach ((array) $route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'authorize.permission:')) {
                $permissions[] = substr($middleware, strlen('authorize.permission:'));
            }
        }

        return $permissions;
    }

    private function deny(Workspace $workspace, Request $request, int $status, string $code): JsonResponse
    {
        $state = $this->state($workspace);

        // One deny row per blocked request, same rule as an authorization deny:
        // the state alone is metadata, never content (N-02).
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => $request->user()?->id,
            'action' => 'workspace.retention_block',
            'target_type' => 'workspace',
            'target_id' => $workspace->id,
            'result' => 'deny',
            'reason_code' => $code,
            'metadata' => [
                'retention_state' => $state->value,
                'method' => $request->method(),
            ],
        ]);

        return ProblemDetails::make(
            $request,
            $status,
            $code,
            self::titleFor($code),
            self::detailFor($code),
            [],
            [],
            [
                'retention_state' => $state->value,
                'retention_expires_at' => $workspace->retention_expires_at?->toIso8601String(),
            ],
        );
    }

    private static function titleFor(string $code): string
    {
        return match ($code) {
            'workspace_read_only' => 'Workspace is read-only',
            'workspace_archived' => 'Workspace is archived',
            'workspace_scheduled_deletion' => 'Workspace is scheduled for deletion',
            'workspace_deleted' => 'Workspace is deleted',
            default => 'Request refused',
        };
    }

    private static function detailFor(string $code): string
    {
        return match ($code) {
            'workspace_read_only' => 'บัญชีนี้อยู่ในสถานะอ่านอย่างเดียว จึงยังแก้ไขข้อมูลไม่ได้ '
                .'กรุณาติดต่อผู้ดูแลหรือกลับมาใช้งานต่อ',
            'workspace_archived' => 'บัญชีนี้ถูกเก็บถาวรและยังไม่พร้อมใช้งาน กรุณากู้คืนบัญชีก่อนเข้าถึงข้อมูล',
            'workspace_scheduled_deletion' => 'บัญชีนี้อยู่ในคิวการลบ กรุณาส่งออกข้อมูลหรือติดต่อผู้ดูแล',
            'workspace_deleted' => 'บัญชีนี้ถูกลบแล้ว',
            default => 'ไม่สามารถดำเนินการได้ในสถานะของบัญชีนี้',
        };
    }
}
