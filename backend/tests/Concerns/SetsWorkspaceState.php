<?php

namespace Tests\Concerns;

use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;

/**
 * Moves a test workspace into a retention state without going through the
 * lifecycle service.
 *
 * The service is the right path for behaviour tests (it audits and enforces
 * the arrows); job-filter tests only need the state to already be there, and
 * using the service would add a deadline and an audit row to every fixture.
 */
trait SetsWorkspaceState
{
    protected function setWorkspaceState(RetentionState $state, ?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($workspace->tenant_id, $workspace->id);

        $workspace->forceFill([
            'retention_state' => $state,
            'retention_state_changed_at' => now(),
            'retention_expires_at' => $state === RetentionState::Active ? null : now()->addDays(30),
        ])->save();

        $workspace->refresh();

        // The fixture may have moved the RLS context; leave the caller on the
        // workspace it just changed.
        TenantContext::set($workspace->tenant_id, $workspace->id);
    }
}
