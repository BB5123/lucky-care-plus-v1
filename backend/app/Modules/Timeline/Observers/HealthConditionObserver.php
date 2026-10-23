<?php

namespace App\Modules\Timeline\Observers;

use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\Timeline\Application\TimelineSource;

/**
 * A newly recorded condition becomes a `condition` timeline entry. The
 * diagnosis name and notes stay in the encrypted source row.
 */
final class HealthConditionObserver extends TimelineObserver
{
    public function created(HealthCondition $condition): void
    {
        $this->defer(
            (string) $condition->tenant_id,
            (string) $condition->workspace_id,
            (string) $condition->profile_id,
            TimelineSource::forCondition($condition),
        );
    }
}
