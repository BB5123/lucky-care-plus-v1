<?php

namespace App\Modules\AiGateway\Application;

use App\Models\User;
use App\Modules\AiGateway\Domain\FetchedContext;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\Authz\Authorizer;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * Step 2 โ€” Scoped Data Fetcher (`.ai/08-AI-GATEWAY.md` section 2).
 *
 * A section is fetched only when the actor may read its own scope for this
 * profile; `ai.report.create` does not imply `vitals`, `medications`,
 * `appointments` or `tasks` (F-06). `lab_results` and `mental_health` are not
 * in the section map and can never be reached (ADR-014).
 *
 * The fetcher returns identifiers separately from values. Only the values are
 * scrubbed and sent to the provider; `record_refs` carries `[{type, id}]`.
 */
final class ScopedDataFetcher
{
    /**
     * Section => permission the actor must hold for this profile.
     *
     * @var array<string, string>
     */
    private const SECTION_PERMISSIONS = [
        'profile.basic' => 'health_profile.read',
        'vitals' => 'health_measurement.read',
        'medications' => 'medication_plan.read',
        'appointments' => 'appointment.read',
        'tasks' => 'care_task.read',
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

    public function fetch(GatewayRequest $request): FetchedContext
    {
        $allowed = $this->allowedSections($request->workspace, $request->profile, $request->actor);

        $sections = [];
        $refs = [];
        $personNames = [];

        if ($allowed['profile.basic']) {
            $sections['profile.basic'] = $this->profileBasic($request->profile);
            $this->addRef($refs, 'health_profile', $request->profile->id);

            $person = $request->profile->person;

            if ($person instanceof Person) {
                $this->addRef($refs, 'person', $person->id);

                if (is_string($person->display_name) && $person->display_name !== '') {
                    $personNames[] = $person->display_name;
                }
            }
        }

        if ($allowed['vitals']) {
            [$vitals, $vitalRefs] = $this->vitals(
                $request->profile,
                $request->periodFrom,
                $request->periodTo,
            );

            $sections['vitals'] = $vitals;

            foreach ($vitalRefs as $ref) {
                $this->addRef($refs, 'health_measurement', $ref);
            }
        }

        if ($allowed['medications']) {
            [$medications, $medicationRefs] = $this->medications($request->profile);

            $sections['medications'] = $medications;

            foreach ($medicationRefs as $ref) {
                $this->addRef($refs, 'medication_plan', $ref);
            }
        }

        if ($allowed['appointments']) {
            [$appointments, $appointmentRefs] = $this->appointments(
                $request->profile,
                $request->periodFrom,
                $request->periodTo,
            );

            $sections['appointments'] = $appointments;

            foreach ($appointmentRefs as $ref) {
                $this->addRef($refs, 'appointment', $ref);
            }

            foreach ($appointments as $appointment) {
                $provider = $appointment['provider_name'] ?? null;

                if (is_string($provider) && $provider !== '') {
                    $personNames[] = $provider;
                }
            }
        }

        if ($allowed['tasks']) {
            [$tasks, $taskRefs] = $this->tasks(
                $request->profile,
                $request->periodFrom,
                $request->periodTo,
            );

            $sections['tasks'] = $tasks;

            foreach ($taskRefs as $ref) {
                $this->addRef($refs, 'care_task', $ref);
            }
        }

        usort($refs, static fn (array $a, array $b): int => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);

        return new FetchedContext(
            $sections,
            array_values($refs),
            array_values(array_unique($personNames)),
        );
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
    private function profileBasic(HealthProfile $profile): array
    {
        $profile->loadMissing('person');
        $person = $profile->person;

        $identity = [
            'display_name' => $person?->display_name,
        ];

        $ageGroup = $this->ageGroup($person?->birth_date);

        if ($ageGroup !== null) {
            $identity['age_group'] = $ageGroup;
        }

        return $identity;
    }

    /**
     * Aggregate trends only. Raw free-text notes never leave the database
     * through this path (N-02).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function vitals(
        HealthProfile $profile,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $rows = HealthMeasurement::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->whereNotIn('type', ['lab_results', 'mental_health'])
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get([
                'id',
                'type',
                'value',
                'secondary_value',
                'unit',
                'recorded_at',
            ]);

        if ($rows->isEmpty()) {
            return [[], []];
        }

        $grouped = [];

        foreach ($rows as $row) {
            $type = (string) $row->type;

            if (! isset($grouped[$type])) {
                $grouped[$type] = [
                    'type' => $type,
                    'unit' => (string) $row->unit,
                    'count' => 0,
                    'min' => null,
                    'max' => null,
                    'sum' => 0.0,
                    'latest' => null,
                    'latest_recorded_at' => null,
                ];
            }

            $value = (float) $row->value;
            $group = &$grouped[$type];

            $group['count']++;
            $group['sum'] += $value;
            $group['min'] = $group['min'] === null ? $value : min($group['min'], $value);
            $group['max'] = $group['max'] === null ? $value : max($group['max'], $value);

            $recordedAt = $row->recorded_at;

            if ($group['latest_recorded_at'] === null || $recordedAt->greaterThan($group['latest_recorded_at'])) {
                $group['latest_recorded_at'] = $recordedAt;
                $group['latest'] = [
                    'value' => round($value, 2),
                    'secondary_value' => $row->secondary_value === null ? null : round((float) $row->secondary_value, 2),
                    'day' => $this->relativeDay($recordedAt),
                ];
            }

            unset($group);
        }

        $sections = [];

        foreach ($grouped as $group) {
            $sections[] = [
                'type' => $group['type'],
                'unit' => $group['unit'],
                'count' => $group['count'],
                'min' => round((float) $group['min'], 2),
                'max' => round((float) $group['max'], 2),
                'avg' => round($group['sum'] / max(1, $group['count']), 2),
                'latest' => $group['latest'],
            ];
        }

        usort($sections, static fn (array $a, array $b): int => $a['type'] <=> $b['type']);

        return [$sections, $rows->pluck('id')->all()];
    }

    /**
     * Current plans that overlap the requested window. Free-text instructions
     * and prescriber names are deliberately excluded.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function medications(HealthProfile $profile): array
    {
        $plans = MedicationPlan::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->where('status', MedicationPlan::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', now()->toDateString());
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'dosage',
                'form',
                'doses_per_day',
                'schedule_times',
                'starts_on',
            ]);

        $medications = $plans
            ->map(static fn (MedicationPlan $plan): array => [
                'name' => $plan->name,
                'dosage' => $plan->dosage,
                'form' => $plan->form,
                'doses_per_day' => $plan->doses_per_day,
                'schedule' => $plan->schedule_times,
                'since' => $plan->starts_on?->toDateString(),
            ])
            ->all();

        return [$medications, $plans->pluck('id')->all()];
    }

    /**
     * Visits inside the requested window. Purpose/location are free text and
     * stay out of the AI context.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function appointments(
        HealthProfile $profile,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $appointments = Appointment::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->whereBetween('scheduled_at', [$from, $to])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'provider_name',
                'facility',
                'scheduled_at',
                'duration_minutes',
                'status',
            ]);

        $rows = $appointments
            ->map(fn (Appointment $appointment): array => [
                'title' => $appointment->title,
                'provider_name' => $appointment->provider_name,
                'facility' => $appointment->facility,
                'day' => $this->relativeDay($appointment->scheduled_at),
                'duration_minutes' => $appointment->duration_minutes,
                'status' => $appointment->status,
            ])
            ->all();

        return [$rows, $appointments->pluck('id')->all()];
    }

    /**
     * Counts only. Task titles and descriptions never enter the AI context.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function tasks(
        HealthProfile $profile,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $open = CareTask::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->orderBy('id')
            ->get(['id', 'status']);

        $completed = CareTask::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->where('status', CareTask::STATUS_COMPLETED)
            ->whereBetween('completed_at', [$from, $to])
            ->orderBy('id')
            ->get(['id']);

        $byStatus = [];

        foreach (self::OPEN_TASK_STATUSES as $status) {
            $byStatus[$status] = $open->where('status', $status)->count();
        }

        return [
            [
                'open_total' => $open->count(),
                'by_status' => $byStatus,
                'completed_in_period' => $completed->count(),
            ],
            array_values(array_unique(array_merge($open->pluck('id')->all(), $completed->pluck('id')->all()))),
        ];
    }

    /**
     * @param  array<string, array{type: string, id: string}>  $refs
     */
    private function addRef(array &$refs, string $type, string $id): void
    {
        $refs[$type.'|'.$id] = ['type' => $type, 'id' => $id];
    }

    private function ageGroup(mixed $birthDate): ?string
    {
        if (! $birthDate instanceof CarbonInterface) {
            return null;
        }

        $age = $birthDate->diffInYears(now());

        if ($age < 0) {
            return null;
        }

        $lower = (int) (floor($age / 5) * 5);

        return $lower.'-'.($lower + 4);
    }

    private function relativeDay(?CarbonInterface $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        $days = (int) floor(
            ($moment->copy()->startOfDay()->getTimestamp() - now()->startOfDay()->getTimestamp()) / 86400
        );

        if ($days === 0) {
            return 'Day 0';
        }

        return $days > 0 ? 'Day +'.$days : 'Day '.$days;
    }
}
