<?php

namespace App\Modules\Timeline\Observers;

use App\Modules\Timeline\Application\TimelineProjector;
use App\Modules\Timeline\Domain\TimelineProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared deferral logic for every timeline observer.
 *
 * Projection runs in `DB::afterCommit()`: a rolled-back source write must not
 * leave an orphan timeline row, and the projection must never make the source
 * write fail (CRM-011 — the read model is derived data).
 *
 * The tenant/workspace/profile identity is captured **before** the commit,
 * because the request's transaction-local RLS context is gone by the time the
 * callback runs (ADR-013 / N-01).
 *
 * A projection failure is logged at `error` with reference ids only. Health
 * content never reaches a log line (N-02).
 */
abstract class TimelineObserver
{
    /**
     * @param  list<TimelineProjection>  $projections
     */
    protected function defer(
        string $tenantId,
        string $workspaceId,
        string $profileId,
        array $projections,
    ): void {
        if ($projections === []) {
            return;
        }

        DB::afterCommit(function () use ($tenantId, $workspaceId, $profileId, $projections): void {
            try {
                $projector = app(TimelineProjector::class)
                    ->forSubject($tenantId, $workspaceId, $profileId);
            } catch (Throwable $exception) {
                Log::error('timeline projection unavailable', [
                    'tenant_id' => $tenantId,
                    'workspace_id' => $workspaceId,
                    'error' => $exception::class,
                ]);

                return;
            }

            foreach ($projections as $projection) {
                try {
                    $projector->record(
                        $projection->type,
                        $projection->refType,
                        $projection->refId,
                        $projection->occurredAt,
                        $projection->summaryKey,
                        $projection->metadata,
                    );
                } catch (Throwable $exception) {
                    Log::error('timeline projection failed', [
                        'tenant_id' => $tenantId,
                        'workspace_id' => $workspaceId,
                        'ref_type' => $projection->refType,
                        'ref_id' => $projection->refId,
                        'error' => $exception::class,
                    ]);
                }
            }
        });
    }
}
