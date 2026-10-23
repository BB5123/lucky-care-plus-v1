<?php

namespace App\Modules\Timeline\Observers;

use App\Modules\HealthRecord\Models\MedicationRecord;
use App\Modules\Timeline\Application\TimelineSource;

/**
 * A reported dose becomes a `medication_record` timeline entry. The reported
 * status (`taken|skipped|missed`) is an enum and safe; the free-text note is
 * not projected.
 */
final class MedicationRecordObserver extends TimelineObserver
{
    public function created(MedicationRecord $record): void
    {
        $this->defer(
            (string) $record->tenant_id,
            (string) $record->workspace_id,
            (string) $record->profile_id,
            TimelineSource::forMedicationRecord($record),
        );
    }
}
