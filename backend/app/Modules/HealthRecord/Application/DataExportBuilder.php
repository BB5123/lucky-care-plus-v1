<?php

namespace App\Modules\HealthRecord\Application;

use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\HealthRecord\Models\DataExport;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\HealthRecord\Models\MedicationRecord;
use Illuminate\Support\Collection;

/**
 * Builds the machine-readable copy of one profile's record (PDPA export).
 *
 * The caller (the queued job) must already hold the tenant/workspace context:
 * every query below relies on RLS and on the profile id, never on a client
 * supplied tenant or workspace.
 *
 * Deliberately excluded: document bytes, document storage paths, checksums,
 * audit rows and anything that is not part of the person's own record. A data
 * export is a copy of the record, not a bulk download of images. The document
 * array therefore carries metadata only.
 *
 * Free-text fields that are field-encrypted in the database (`notes`,
 * `instructions`, `purpose`, `location`, `description`, `note`) are included:
 * the data subject is entitled to the values they hold. They are never copied
 * into a log, an audit row, a URL or a file name (N-02).
 */
final class DataExportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(DataExport $export, HealthProfile $profile): array
    {
        $profile->loadMissing('person');

        $tasks = CareTask::query()
            ->where('profile_id', $profile->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $taskIds = $tasks->pluck('id')->all();

        return [
            'meta' => [
                'export_id' => $export->id,
                'profile_id' => $profile->id,
                'generated_at' => now()->toIso8601String(),
                'format' => DataExport::FORMAT_JSON,
                'format_version' => (int) config('exports.format_version', 1),
                'notice' => 'ไฟล์นี้มีข้อมูลสุขภาพ โปรดเก็บรักษาและเปิดเผยตามที่กฎหมายอนุญาตเท่านั้น',
            ],
            'profile' => $this->profile($profile),
            'conditions' => $this->conditions($profile),
            'allergies' => $this->allergies($profile),
            'measurements' => $this->measurements($profile),
            'medication_plans' => $this->medicationPlans($profile),
            'medication_records' => $this->medicationRecords($profile),
            'appointments' => $this->appointments($profile),
            'care_tasks' => $this->careTasks($tasks),
            'task_events' => $this->taskEvents($taskIds),
            'documents' => $this->documents($profile),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(HealthProfile $profile): array
    {
        $person = $profile->person;

        return [
            'id' => $profile->id,
            'person_id' => $profile->person_id,
            'display_name' => $person?->display_name,
            'birth_date' => $person?->birth_date?->toDateString(),
            'managed_by_person_id' => $profile->managed_by_person_id,
            'archived_at' => $profile->archived_at?->toIso8601String(),
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conditions(HealthProfile $profile): array
    {
        return HealthCondition::query()
            ->where('profile_id', $profile->id)
            ->orderBy('diagnosed_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (HealthCondition $condition): array => [
                'id' => $condition->id,
                'code' => $condition->code,
                'name' => $condition->name,
                'diagnosed_at' => $condition->diagnosed_at?->toDateString(),
                'status' => $condition->status,
                'notes' => $condition->notes,
                'source' => $condition->source,
                'created_at' => $condition->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allergies(HealthProfile $profile): array
    {
        return Allergy::query()
            ->where('profile_id', $profile->id)
            ->orderBy('substance')
            ->orderBy('id')
            ->get()
            ->map(static fn (Allergy $allergy): array => [
                'id' => $allergy->id,
                'substance' => $allergy->substance,
                'reaction' => $allergy->reaction,
                'severity' => $allergy->severity,
                'noted_at' => $allergy->noted_at?->toDateString(),
                'source' => $allergy->source,
                'created_at' => $allergy->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function measurements(HealthProfile $profile): array
    {
        return HealthMeasurement::query()
            ->where('profile_id', $profile->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (HealthMeasurement $measurement): array => [
                'id' => $measurement->id,
                'type' => $measurement->type,
                'value' => (float) $measurement->value,
                'secondary_value' => $measurement->secondary_value === null
                    ? null
                    : (float) $measurement->secondary_value,
                'unit' => $measurement->unit,
                'context' => $measurement->context,
                'note' => $measurement->note,
                'recorded_at' => $measurement->recorded_at?->toIso8601String(),
                'source' => $measurement->source,
                'created_at' => $measurement->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medicationPlans(HealthProfile $profile): array
    {
        return MedicationPlan::query()
            ->where('profile_id', $profile->id)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicationPlan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'dosage' => $plan->dosage,
                'form' => $plan->form,
                'route' => $plan->route,
                'doses_per_day' => $plan->doses_per_day,
                'schedule_times' => $plan->schedule_times,
                'starts_on' => $plan->starts_on?->toDateString(),
                'ends_on' => $plan->ends_on?->toDateString(),
                'prescribed_by' => $plan->prescribed_by,
                'instructions' => $plan->instructions,
                'status' => $plan->status,
                'discontinued_reason' => $plan->discontinued_reason,
                'discontinued_at' => $plan->discontinued_at?->toIso8601String(),
                'source' => $plan->source,
                'created_at' => $plan->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medicationRecords(HealthProfile $profile): array
    {
        return MedicationRecord::query()
            ->where('profile_id', $profile->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicationRecord $record): array => [
                'id' => $record->id,
                'plan_id' => $record->plan_id,
                'status' => $record->status,
                'scheduled_for' => $record->scheduled_for?->toIso8601String(),
                'recorded_at' => $record->recorded_at?->toIso8601String(),
                'note' => $record->note,
                'source' => $record->source,
                'created_at' => $record->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function appointments(HealthProfile $profile): array
    {
        return Appointment::query()
            ->where('profile_id', $profile->id)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'title' => $appointment->title,
                'provider_name' => $appointment->provider_name,
                'facility' => $appointment->facility,
                'purpose' => $appointment->purpose,
                'location' => $appointment->location,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'duration_minutes' => $appointment->duration_minutes,
                'status' => $appointment->status,
                'cancelled_reason' => $appointment->cancelled_reason,
                'rescheduled_from_id' => $appointment->rescheduled_from_id,
                'transported_by_task_id' => $appointment->transported_by_task_id,
                'prepared_documents' => $appointment->prepared_documents,
                'follow_up_task_id' => $appointment->follow_up_task_id,
                'created_at' => $appointment->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, CareTask>  $tasks
     * @return list<array<string, mixed>>
     */
    private function careTasks($tasks): array
    {
        return $tasks
            ->map(static fn (CareTask $task): array => [
                'id' => $task->id,
                'template_id' => $task->template_id,
                'tier' => $task->tier,
                'type' => $task->type,
                'title' => $task->title,
                'description' => $task->description,
                'priority' => $task->priority,
                'scheduled_for' => $task->scheduled_for?->toIso8601String(),
                'due_at' => $task->due_at?->toIso8601String(),
                'timezone' => $task->timezone,
                'status' => $task->status,
                'outcome' => $task->outcome,
                'verification' => $task->verification,
                'verification_required' => $task->verification_required,
                'evidence_policy' => $task->evidence_policy,
                'escalation_level' => $task->escalation_level,
                'blocked_reason' => $task->blocked_reason,
                'review_at' => $task->review_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'source' => $task->source,
                'created_at' => $task->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $taskIds
     * @return list<array<string, mixed>>
     */
    private function taskEvents(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        return TaskEvent::query()
            ->whereIn('task_id', $taskIds)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (TaskEvent $event): array => [
                'id' => $event->id,
                'task_id' => $event->task_id,
                'actor_id' => $event->actor_id,
                'actor_type' => $event->actor_type,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'event' => $event->event,
                'reason' => $event->reason,
                'metadata' => $event->metadata,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Metadata only. The bytes, the storage path and the checksum are never
     * part of an export: a data export is a copy of the record, not a bulk
     * image download.
     *
     * @return list<array<string, mixed>>
     */
    private function documents(HealthProfile $profile): array
    {
        return MedicalDocument::query()
            ->where('profile_id', $profile->id)
            ->where('status', MedicalDocument::STATUS_AVAILABLE)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalDocument $document): array => [
                'id' => $document->id,
                'type' => $document->kind,
                'original_name' => $document->original_name,
                'created_at' => $document->created_at?->toIso8601String(),
                'size_bytes' => $document->size_bytes,
            ])
            ->values()
            ->all();
    }
}
