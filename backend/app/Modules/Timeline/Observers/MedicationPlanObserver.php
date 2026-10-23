<?php

namespace App\Modules\Timeline\Observers;

use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\Timeline\Application\TimelineSource;

/**
 * A plan start is projected on create; a plan stop is projected when the
 * domain service flips the status to `discontinued`.
 *
 * Using two ref types keeps both facts under the unique source key, so a plan
 * that was started and later stopped appears twice in the timeline — once for
 * each lifecycle moment.
 */
final class MedicationPlanObserver extends TimelineObserver
{
    public function created(MedicationPlan $plan): void
    {
        $this->defer(
            (string) $plan->tenant_id,
            (string) $plan->workspace_id,
            (string) $plan->profile_id,
            [TimelineSource::medicationPlanCreated($plan)],
        );
    }

    public function updated(MedicationPlan $plan): void
    {
        if (
            ! $plan->wasChanged('status')
            || $plan->status !== MedicationPlan::STATUS_DISCONTINUED
        ) {
            return;
        }

        $this->defer(
            (string) $plan->tenant_id,
            (string) $plan->workspace_id,
            (string) $plan->profile_id,
            [TimelineSource::medicationPlanDiscontinued($plan)],
        );
    }
}
