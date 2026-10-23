<?php

namespace App\Modules\Timeline\Application;

use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\HealthRecord\Models\MedicationRecord;
use App\Modules\Timeline\Domain\TimelineProjection;
use App\Modules\Timeline\Domain\TimelineType;
use DateTimeInterface;

/**
 * The single "source row -> timeline projection" mapping (CRM-011).
 *
 * Live observers and `timeline:rebuild` both call this class, so a rebuild can
 * never invent a different entry than the one the write path produced.
 *
 * Metadata carries enum-safe context only. Measured values, notes, names,
 * substances, titles and any other free text are deliberately absent (DM-007 /
 * CRM-012); the projector rejects them if they ever appear.
 */
final class TimelineSource
{
    /**
     * @return list<TimelineProjection>
     */
    public static function forMeasurement(HealthMeasurement $source): array
    {
        return [new TimelineProjection(
            TimelineType::MEASUREMENT,
            'health_measurement',
            (string) $source->id,
            self::clock($source->recorded_at, $source->created_at),
            'timeline.measurement.recorded',
            ['type' => (string) $source->type],
        )];
    }

    /**
     * @return list<TimelineProjection>
     */
    public static function forCondition(HealthCondition $source): array
    {
        return [new TimelineProjection(
            TimelineType::CONDITION,
            'health_condition',
            (string) $source->id,
            self::clock($source->diagnosed_at, $source->created_at),
            'timeline.condition.recorded',
        )];
    }

    /**
     * @return list<TimelineProjection>
     */
    public static function forAllergy(Allergy $source): array
    {
        return [new TimelineProjection(
            TimelineType::ALLERGY,
            'allergy',
            (string) $source->id,
            self::clock($source->noted_at, $source->created_at),
            'timeline.allergy.recorded',
        )];
    }

    /**
     * Both lifecycle entries, because a discontinued plan has two facts worth
     * keeping: it was started, and it was stopped. They use distinct ref types
     * so the `(ref_type, ref_id, type)` unique key can hold both.
     *
     * @return list<TimelineProjection>
     */
    public static function forMedicationPlan(MedicationPlan $source): array
    {
        $projections = [self::medicationPlanCreated($source)];

        if ($source->status === MedicationPlan::STATUS_DISCONTINUED) {
            $projections[] = self::medicationPlanDiscontinued($source);
        }

        return $projections;
    }

    public static function medicationPlanCreated(MedicationPlan $source): TimelineProjection
    {
        return new TimelineProjection(
            TimelineType::MEDICATION_PLAN,
            'medication_plan',
            (string) $source->id,
            self::clock($source->created_at, null),
            'timeline.medication_plan.created',
        );
    }

    public static function medicationPlanDiscontinued(MedicationPlan $source): TimelineProjection
    {
        return new TimelineProjection(
            TimelineType::MEDICATION_PLAN,
            'medication_plan_discontinued',
            (string) $source->id,
            self::clock($source->discontinued_at, $source->updated_at),
            'timeline.medication_plan.discontinued',
        );
    }

    /**
     * @return list<TimelineProjection>
     */
    public static function forMedicationRecord(MedicationRecord $source): array
    {
        return [new TimelineProjection(
            TimelineType::MEDICATION_RECORD,
            'medication_record',
            (string) $source->id,
            self::clock($source->recorded_at, $source->created_at),
            'timeline.medication_record.recorded',
            ['status' => (string) $source->status],
        )];
    }

    /**
     * One entry per transition (CT-004). The task supplies the profile because
     * `task_events` itself is workspace-scoped, not profile-scoped.
     *
     * @return list<TimelineProjection>
     */
    public static function forTaskEvent(TaskEvent $event, ?CareTask $task): array
    {
        if (! $task instanceof CareTask) {
            return [];
        }

        $name = (string) $event->event;

        return [new TimelineProjection(
            TimelineType::CARE_TASK,
            'task_event',
            (string) $event->id,
            self::clock($event->occurred_at, $event->created_at),
            'timeline.care_task.'.($name === '' ? 'updated' : $name),
            [
                'event' => $name,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
            ],
        )];
    }

    /**
     * @return list<TimelineProjection>
     */
    public static function forAppointment(Appointment $source): array
    {
        $projections = [self::appointmentCreated($source)];

        if ($source->status === Appointment::STATUS_ATTENDED) {
            $projections[] = self::appointmentAttended($source);
        }

        return $projections;
    }

    public static function appointmentCreated(Appointment $source): TimelineProjection
    {
        return new TimelineProjection(
            TimelineType::APPOINTMENT,
            'appointment',
            (string) $source->id,
            self::clock($source->created_at, null),
            'timeline.appointment.created',
        );
    }

    public static function appointmentAttended(Appointment $source): TimelineProjection
    {
        return new TimelineProjection(
            TimelineType::APPOINTMENT,
            'appointment_attended',
            (string) $source->id,
            self::clock($source->updated_at, $source->scheduled_at),
            'timeline.appointment.attended',
        );
    }

    private static function clock(?DateTimeInterface $primary, ?DateTimeInterface $fallback): string
    {
        return ($primary ?? $fallback ?? now())->toIso8601String();
    }
}
