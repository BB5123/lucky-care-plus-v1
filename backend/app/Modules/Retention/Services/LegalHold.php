<?php

namespace App\Modules\Retention\Services;

use App\Modules\Retention\Models\DataHold;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Legal hold lookups and release (ADR-016 §3 / DM-R04).
 *
 * A hold is active when it has not been released and (`until` is null or in
 * the future). Any active hold — workspace-wide or for the given profile —
 * wins: purging is skipped for the row.
 *
 * `isHeld($workspaceId, null)` asks whether the *whole workspace* is held, so
 * a profile-only hold does not match it. Callers that purge a specific row
 * pass the row's profile id, or use `activeHolds()` to separate the two cases.
 */
final class LegalHold
{
    public function isHeld(string $workspaceId, ?string $profileId = null): bool
    {
        $query = DataHold::query()
            ->where('workspace_id', $workspaceId)
            ->active();

        if ($profileId === null) {
            $query->whereNull('profile_id');
        } else {
            $query->where(function ($query) use ($profileId): void {
                $query->whereNull('profile_id')->orWhere('profile_id', $profileId);
            });
        }

        return $query->exists();
    }

    /**
     * Active holds for a workspace, read once per purge batch.
     *
     * @return Collection<int, DataHold>
     */
    public function activeHolds(string $workspaceId): Collection
    {
        return DataHold::query()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->get();
    }

    /**
     * @return list<string>
     */
    public function heldProfileIds(string $workspaceId): array
    {
        return $this->activeHolds($workspaceId)
            ->whereNotNull('profile_id')
            ->pluck('profile_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Release a hold. Releasing an already released hold is a no-op
     * (idempotent) and returns false so callers can distinguish the cases.
     */
    public function release(
        string $holdId,
        ?int $releasedBy = null,
        ?CarbonInterface $at = null,
    ): bool {
        return DataHold::query()
            ->whereKey($holdId)
            ->whereNull('released_at')
            ->update([
                'released_at' => $at ?? now(),
                'released_by' => $releasedBy,
                'updated_at' => now(),
            ]) > 0;
    }
}
