<?php

namespace App\Modules\CareCrm\Application;

use App\Modules\CareCrm\Models\EscalationPolicy;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read/write use cases for the workspace escalation ladder.
 *
 * Replacing the ladder is a single transaction: either the whole proposed
 * ladder lands or the previous one survives untouched. The request layer has
 * already validated shape (levels contiguous from 1, strictly increasing
 * delays, known roles); this service only persists it.
 */
final class EscalationPolicyService
{
    /**
     * @return Collection<int, EscalationPolicy>
     */
    public function listFor(Workspace $workspace): Collection
    {
        return EscalationPolicy::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->orderBy('level')
            ->get();
    }

    /**
     * Replace the whole ladder atomically.
     *
     * @param  list<array<string, mixed>>  $policies
     * @return Collection<int, EscalationPolicy>
     */
    public function replace(Workspace $workspace, array $policies): Collection
    {
        return DB::transaction(function () use ($workspace, $policies): Collection {
            EscalationPolicy::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->delete();

            foreach ($policies as $policy) {
                EscalationPolicy::query()->create([
                    'tenant_id' => $workspace->tenant_id,
                    'workspace_id' => $workspace->id,
                    'level' => (int) $policy['level'],
                    'after_minutes' => (int) $policy['after_minutes'],
                    'notify_assignee' => (bool) ($policy['notify_assignee'] ?? true),
                    'notify_roles' => array_values(array_map('strval', $policy['notify_roles'] ?? [])),
                    'action' => (string) ($policy['action'] ?? EscalationPolicy::ACTION_NOTIFY),
                    'is_active' => (bool) ($policy['is_active'] ?? true),
                ]);
            }

            return $this->listFor($workspace);
        });
    }

    /**
     * Apply the suggestion from `config/escalation.php`.
     *
     * The defaults are deliberately not seeded: applying them is an explicit
     * workspace action, and a workspace that never calls this endpoint keeps
     * escalating nothing.
     *
     * @return Collection<int, EscalationPolicy>
     */
    public function applyDefaults(Workspace $workspace): Collection
    {
        /** @var list<array<string, mixed>> $defaults */
        $defaults = config('escalation.defaults', []);

        return $this->replace($workspace, $defaults);
    }
}
