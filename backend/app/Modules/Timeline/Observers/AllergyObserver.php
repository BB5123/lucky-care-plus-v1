<?php

namespace App\Modules\Timeline\Observers;

use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\Timeline\Application\TimelineSource;

/**
 * A newly recorded allergy becomes an `allergy` timeline entry. Substance and
 * reaction are health detail and are never projected.
 */
final class AllergyObserver extends TimelineObserver
{
    public function created(Allergy $allergy): void
    {
        $this->defer(
            (string) $allergy->tenant_id,
            (string) $allergy->workspace_id,
            (string) $allergy->profile_id,
            TimelineSource::forAllergy($allergy),
        );
    }
}
