<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Authz\Authorizer;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * Builds the frozen Doctor Brief payload (`.ai/05 §9`, DB-001..DB-005).
 *
 * Authorization is per section, not per endpoint: a caregiver who holds only a
 * `vitals` grant can generate a brief, but that brief cannot contain conditions,
 * allergies, medication, appointments or task counts they could not read
 * themselves (F-06, DB-002). {@see Authorizer::authorizeBatch()} appends one
 * audit decision per section.
 *
 * Deliberately excluded: raw lab results (`lab_results` is disabled by ADR-014),
 * mental-health data, condition notes, allergy reactions, medication
 * instructions, and appointment purpose/location.
 */
final class DoctorBriefBuilder
{
    /**
     * Section key => permission the generating actor must hold for the profile.
     * `profile` is the identity header; the six data sections are listed in
     * `sections` only when they are actually present.
     *
     * @var array<string, string>
     */
    private const SECTION_PERMISSIONS = [
        'profile' => 'health_profile.read',
        'conditions' => 'health_condition.read',
        'allergies' => 'allergy.read',
        'medications' => 'medication_plan.read',
        'vitals' => 'health_measurement.read',
        'appointments' => 'appointment.read',
        'care_tasks' => 'care_task.read',
    ];

    /** Task statuses that still need coordination. */
    private const OPEN_TASK_STATUSES = [
        CareTask::STATUS_SCHEDULED,
        CareTask::STATUS_OPEN,
        CareTask::STATUS_ASSIGNED,
        CareTask::STATUS_ACCEPTED,
        CareTask::STATUS_IN_PROGRESS,
        CareTask::STATUS_BLOCKED,
    ];

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        Workspace $workspace,
        HealthProfile $profile,
        User $actor,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $allowed = $this->allowedSections($workspace, $profile, $actor);

        $payload = [
            'kind' => 'doctor_brief',
            'generated_at' => now()->toIso8601String(),
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'sections' => [],
        ];

        if ($allowed['profile']) {
            $payload['profile'] = $this->identity($profile);
        }

        if ($allowed['conditions']) {
            $payload['conditions'] = $this->conditions($profile);
            $payload['sections'][] = 'conditions';
        }

        if ($allowed['allergies']) {
            $payload['allergies'] = $this->allergies($profile);
            $payload['sections'][] = 'allergies';
        }

        if ($allowed['medications']) {
            $payload['medications'] = $this->medications($profile);
            $payload['sections'][] = 'medications';
        }

        if ($allowed['vitals']) {
            $payload['vitals'] = $this->vitals($profile, $from, $to);
            $payload['sections'][] = 'vitals';
        }

        if ($allowed['appointments']) {
            $payload['appointments'] = $this->appointments($profile);
            $payload['sections'][] = 'appointments';
        }

        if ($allowed['care_tasks']) {
            $payload['care_tasks'] = $this->careTasks($profile);
            $payload['sections'][] = 'care_tasks';
        }

        return $payload;
    }

    /**
     * @return array<string, bool>
     */
    private function allowedSections(Workspace $workspace, HealthProfile $profile, User $actor): array
    {
        $sections = array_keys(self::SECTION_PERMISSIONS);

        $checks = array_map(
            static fn (string $section): array => [
                'permission' => self::SECTION_PERMISSIONS[$section],
                'profile_id' => $profile->id,
            ],
            $sections,
        );

        $decisions = $this->authorizer->authorizeBatch($actor, $workspace, $checks);
        $allowed = [];

        foreach ($sections as $index => $section) {
            $allowed[$section] = $decisions[$index]->allowed();
        }

        return $allowed;
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(HealthProfile $profile): array
    {
        $profile->loadMissing('person');
        $person = $profile->person;

        $identity = [
            'name' => $person?->display_name,
            'birth_year' => $person?->birth_date?->year,
        ];

        // There is no blood-type column in the Phase 2 schema. Read it through
        // the model when a later migration adds one, and omit the key entirely
        // while it is absent ("if present" in the brief contract).
        $bloodType = $profile->getAttribute('blood_type')
            ?? $person?->getAttribute('blood_type');

        if (is_string($bloodType) && $bloodType !== '') {
            $identity['blood_type'] = $bloodType;
        }

        return $identity;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conditions(HealthProfile $profile): array
    {
        return HealthCondition::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'active')
            ->orderBy('diagnosed_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (HealthCondition $condition): array => [
                'name' => $condition->name,
                'diagnosed_year' => $condition->diagnosed_at?->year,
                'status' => $condition->status,
            ])
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
                'substance' => $allergy->substance,
                'severity' => $allergy->severity,
            ])
            ->all();
    }

    /**
     * Current plans only: the dose history in `medication_records` is
     * deliberately not part of a Doctor Brief.
     *
     * @return list<array<string, mixed>>
     */
    private function medications(HealthProfile $profile): array
    {
        return MedicationPlan::query()
            ->where('profile_id', $profile->id)
            ->where('status', MedicationPlan::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', now()->toDateString());
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicationPlan $plan): array => [
                'name' => $plan->name,
                'dosage' => $plan->dosage,
                'schedule' => $plan->schedule_times,
                'since' => $plan->starts_on?->toDateString(),
            ])
            ->all();
    }

    /**
     * Aggregate trends only. Raw readings (and therefore any lab-like values)
     * never leave the database through this path.
     *
     * @return list<array<string, mixed>>
     */
    private function vitals(HealthProfile $profile, CarbonInterface $from, CarbonInterface $to): array
    {
        return HealthMeasurement::query()
            ->where('profile_id', $profile->id)
            ->whereBetween('recorded_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw(
                'type, unit, count(*) as reading_count, min(value) as min_value, '
                .'max(value) as max_value, avg(value) as avg_value'
            )
            ->groupBy('type', 'unit')
            ->orderBy('type')
            ->get()
            ->map(static fn ($row): array => [
                'type' => $row->type,
                'unit' => $row->unit,
                'min' => round((float) $row->min_value, 2),
                'max' => round((float) $row->max_value, 2),
                'avg' => round((float) $row->avg_value, 2),
                'count' => (int) $row->reading_count,
            ])
            ->all();
    }

    /**
     * Attended visits from the last 90 days plus every upcoming visit.
     *
     * @return list<array<string, mixed>>
     */
    private function appointments(HealthProfile $profile): array
    {
        $now = now();

        return Appointment::query()
            ->where('profile_id', $profile->id)
            ->where(function ($query) use ($now): void {
                $query->where(function ($query) use ($now): void {
                    $query->where('status', Appointment::STATUS_ATTENDED)
                        ->where('scheduled_at', '>=', $now->copy()->subDays(90));
                })->orWhere(function ($query) use ($now): void {
                    $query->whereIn('status', [
                        Appointment::STATUS_PLANNED,
                        Appointment::STATUS_CONFIRMED,
                        Appointment::STATUS_RESCHEDULED,
                    ])->where('scheduled_at', '>=', $now);
                });
            })
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (Appointment $appointment): array => [
                'title' => $appointment->title,
                'provider_name' => $appointment->provider_name,
                'facility' => $appointment->facility,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'status' => $appointment->status,
            ])
            ->all();
    }

    /**
     * Counts by status only. Task titles are free text and never enter the
     * payload (DB-001).
     *
     * @return array{total_open: int, by_status: array<string, int>}
     */
    private function careTasks(HealthProfile $profile): array
    {
        $counts = CareTask::query()
            ->where('profile_id', $profile->id)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];

        foreach (self::OPEN_TASK_STATUSES as $status) {
            $byStatus[$status] = (int) ($counts[$status] ?? 0);
        }

        return [
            'total_open' => array_sum($byStatus),
            'by_status' => $byStatus,
        ];
    }
}
