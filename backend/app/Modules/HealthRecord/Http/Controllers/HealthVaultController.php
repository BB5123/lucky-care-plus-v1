<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HealthRecord\Domain\HealthRecordNotFoundException;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Resolves the trusted route context for every Health Vault controller.
 *
 * The Authorizer middleware has already proven the workspace membership and
 * profile visibility; these helpers re-read the same server-side values and
 * never accept tenant/workspace/profile identity from the request body.
 */
abstract class HealthVaultController extends Controller
{
    protected function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        if (! $workspace instanceof Workspace) {
            throw new HealthRecordNotFoundException;
        }

        return $workspace;
    }

    protected function profile(Request $request): HealthProfile
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
            throw new HealthRecordNotFoundException;
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function limit(array $validated, int $default = 20): int
    {
        return (int) ($validated['limit'] ?? $default);
    }

    /**
     * @return array{next_cursor: ?string, limit: int}
     */
    protected function paginationMeta(CursorPaginator $paginator): array
    {
        return [
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'limit' => $paginator->perPage(),
        ];
    }
}
