<?php

namespace App\Modules\Timeline\Observers;

use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\Timeline\Application\TimelineSource;

/**
 * A newly recorded vital reading becomes a `measurement` timeline entry.
 * The reading's value and note never leave the source table.
 */
final class HealthMeasurementObserver extends TimelineObserver
{
    public function created(HealthMeasurement $measurement): void
    {
        $this->defer(
            (string) $measurement->tenant_id,
            (string) $measurement->workspace_id,
            (string) $measurement->profile_id,
            TimelineSource::forMeasurement($measurement),
        );
    }
}
