<?php

namespace App\Providers;

use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\HealthRecord\Models\MedicationRecord;
use App\Modules\Timeline\Observers\AllergyObserver;
use App\Modules\Timeline\Observers\AppointmentObserver;
use App\Modules\Timeline\Observers\HealthConditionObserver;
use App\Modules\Timeline\Observers\HealthMeasurementObserver;
use App\Modules\Timeline\Observers\MedicationPlanObserver;
use App\Modules\Timeline\Observers\MedicationRecordObserver;
use App\Modules\Timeline\Observers\TaskEventObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the timeline projection to the source modules' model events (P1.7).
 *
 * Registration lives in one short list so "what feeds the timeline?" is
 * answered by reading this file, not by grepping every service. Each observer
 * defers the write to `afterCommit`, so the projection can never block or
 * roll back the source write.
 */
final class TimelineServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        HealthMeasurement::observe(HealthMeasurementObserver::class);
        HealthCondition::observe(HealthConditionObserver::class);
        Allergy::observe(AllergyObserver::class);
        MedicationPlan::observe(MedicationPlanObserver::class);
        MedicationRecord::observe(MedicationRecordObserver::class);
        TaskEvent::observe(TaskEventObserver::class);
        Appointment::observe(AppointmentObserver::class);
    }
}
