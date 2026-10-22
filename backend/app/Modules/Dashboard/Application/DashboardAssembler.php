<?php

namespace App\Modules\Dashboard\Application;

use App\Models\User;
use App\Modules\Authz\Authorizer;
use App\Modules\Authz\Decision;
use App\Modules\CareCrm\Http\Resources\CareTaskCardResource;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\Consent\Services\AuthzVersion;
use App\Modules\Dashboard\Domain\DashboardNotFoundException;
use App\Modules\HealthRecord\Domain\MeasurementType;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\CareRelationship;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only composition layer for the first screen (ADR-010).
 *
 * This is deliberately not a domain module: it owns no tables and no write
 * path. It reads the owning modules' models through the same authorization
 * entry point as their own endpoints (`Authorizer::authorizeBatch`), so each
 * card is proven separately before any of its data enters the payload
 * (CRM-023, AP-003).
 *
 * Data-leak rules enforced here:
 *  - a hidden card is `{visible:false, reason}` and contains nothing else;
 *  - a ring/alert is derived only from cards the actor may read, so a
 *    `profile.basic` reader cannot infer a vital value from a red ring;
 *  - `lab_results` / `mental_health` are never queried (ADR-014);
 *  - task items use the P1.3 safe-card projection only;
 *  - alerts carry a translation key, never free text with a value (N-02).
 */
final class DashboardAssembler
{
    /** The four existing permission IDs the five cards are built from. */
    public const CARD_PERMISSIONS = [
        'person.read',
        'care_task.read',
        'health_measurement.read',
        'appointment.read',
    ];

    private const RING_RANK = ['inner' => 0, 'support' => 1, 'professional' => 2];

    private const STATUS_RANK = ['red' => 0, 'yellow' => 1, 'green' => 2];

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(
        Workspace $workspace,
        User $actor,
        ?string $profileId,
        bool $bypassCache,
    ): array {
        $cacheKey = $this->cacheKey($workspace, $actor, $profileId, AuthzVersion::current($workspace->id));

        if (! $bypassCache) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $payload = $this->build($workspace, $actor, $profileId);

        if (! $bypassCache) {
            Cache::put($cacheKey, $payload, (int) config('dashboard.cache_ttl', 30));
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Workspace $workspace, User $actor, ?string $profileId): array
    {
        $profiles = $this->profiles($workspace);

        if ($profileId !== null) {
            $profiles = $profiles->where('id', $profileId)->values();

            if ($profiles->isEmpty()) {
                throw new DashboardNotFoundException;
            }
        }

        [$checks, $decisions] = $this->authorizeCards($workspace, $actor, $profiles);
        $allowed = $this->allowedProfileIds($checks, $decisions);

        // A profile the actor may not see through *any* card is a 404, not an
        // empty payload: the caller asked for a specific person (AP-008).
        if ($profileId !== null && $allowed === []) {
            throw new DashboardNotFoundException;
        }

        $tasks = $this->tasks($workspace, $actor, $allowed['care_task.read'] ?? []);
        $tasksVisible = ($allowed['care_task.read'] ?? []) !== [] || $tasks->isNotEmpty();

        $measurements = $this->measurements($workspace, $allowed['health_measurement.read'] ?? []);
        $appointments = $this->appointments($workspace, $allowed['appointment.read'] ?? []);

        $vitals = $this->vitals($profiles, $measurements, $allowed['health_measurement.read'] ?? []);
        $rings = $this->rings($workspace, $actor, $profiles, $allowed, $measurements, $tasks, $appointments);

        $alertsVisible = ($allowed['health_measurement.read'] ?? []) !== []
            || ($allowed['care_task.read'] ?? []) !== []
            || $tasksVisible
            || ($allowed['appointment.read'] ?? []) !== [];

        return [
            'family' => $this->family($profiles, $rings, $allowed['person.read'] ?? [], $this->hiddenReason($checks, $decisions, 'person.read')),
            'alerts' => $alertsVisible
                ? ['visible' => true, 'items' => $this->alerts($profiles, $measurements, $tasks, $appointments)]
                : ['visible' => false, 'reason' => 'out_of_scope'],
            'tasks_today' => $tasksVisible
                ? $this->tasksToday($tasks, $tasksVisible)
                : ['visible' => false, 'reason' => $this->hiddenReason($checks, $decisions, 'care_task.read')],
            'vitals' => ($allowed['health_measurement.read'] ?? []) !== []
                ? ['visible' => true, 'cards' => $vitals]
                : ['visible' => false, 'reason' => $this->hiddenReason($checks, $decisions, 'health_measurement.read')],
            'next_appointment' => ($allowed['appointment.read'] ?? []) !== []
                ? $this->nextAppointment($appointments)
                : ['visible' => false, 'reason' => $this->hiddenReason($checks, $decisions, 'appointment.read')],
            'generated_at' => now()->toIso8601String(),
            'cache_ttl' => (int) config('dashboard.cache_ttl', 30),
        ];
    }

    /**
     * @return Collection<int, HealthProfile>
     */
    private function profiles(Workspace $workspace): Collection
    {
        return HealthProfile::query()
            ->where('workspace_id', $workspace->id)
            ->with('person')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * One batch call proves every `(card, profile)` pair. The checks are built
     * in profile order so the returned list can be mapped back by index.
     *
     * @param  Collection<int, HealthProfile>  $profiles
     * @return array{0: list<array{permission: string, profile_id: ?string}>, 1: list<Decision>}
     */
    private function authorizeCards(Workspace $workspace, User $actor, Collection $profiles): array
    {
        $checks = [];

        foreach ($profiles as $profile) {
            foreach (self::CARD_PERMISSIONS as $permission) {
                $checks[] = ['permission' => $permission, 'profile_id' => (string) $profile->id];
            }
        }

        return [$checks, $this->authorizer->authorizeBatch($actor, $workspace, $checks)];
    }

    /**
     * @param  list<array{permission: string, profile_id: ?string}>  $checks
     * @param  list<Decision>  $decisions
     * @return array<string, array<string, true>>
     */
    private function allowedProfileIds(array $checks, array $decisions): array
    {
        $allowed = [];

        foreach ($checks as $index => $check) {
            if ($decisions[$index]->allowed()) {
                $allowed[$check['permission']][(string) $check['profile_id']] = true;
            }
        }

        return $allowed;
    }

    /**
     * @param  list<array{permission: string, profile_id: ?string}>  $checks
     * @param  list<Decision>  $decisions
     */
    private function hiddenReason(array $checks, array $decisions, string $permission): string
    {
        foreach ($checks as $index => $check) {
            if ($check['permission'] !== $permission) {
                continue;
            }

            if (! $decisions[$index]->allowed()) {
                return $decisions[$index]->code();
            }
        }

        return 'out_of_scope';
    }

    /**
     * @param  array<string, true>  $allowedProfiles
     * @return Collection<int, HealthMeasurement>
     */
    private function measurements(Workspace $workspace, array $allowedProfiles): Collection
    {
        if ($allowedProfiles === []) {
            return collect();
        }

        return HealthMeasurement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('profile_id', array_keys($allowedProfiles))
            ->where('recorded_at', '>=', now()->subDays((int) config('dashboard.trend_days', 7)))
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Today's actionable set: everything due by the end of today that is still
     * open, plus tasks completed today (so the progress bar can count them).
     * Tasks assigned to the actor remain visible as safe cards even without a
     * `tasks` grant (F-04 / AS-004).
     *
     * @param  array<string, true>  $taskProfiles
     * @return Collection<int, CareTask>
     */
    private function tasks(Workspace $workspace, User $actor, array $taskProfiles): Collection
    {
        $startOfDay = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        return CareTask::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', CareTask::STATUS_CANCELLED)
            ->where('due_at', '<=', $endOfDay)
            ->where(function (Builder $scope) use ($startOfDay): void {
                $scope->where('status', '!=', CareTask::STATUS_COMPLETED)
                    ->orWhere('due_at', '>=', $startOfDay);
            })
            ->where(function (Builder $scope) use ($taskProfiles, $actor): void {
                if ($taskProfiles !== []) {
                    $scope->whereIn('profile_id', array_keys($taskProfiles))
                        ->orWhereHas('assignments', fn (Builder $assignment) => $assignment
                            ->where('user_id', $actor->id)
                            ->whereNull('released_at'));
                } else {
                    $scope->whereHas('assignments', fn (Builder $assignment) => $assignment
                        ->where('user_id', $actor->id)
                        ->whereNull('released_at'));
                }
            })
            ->with(['profile.person'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit((int) config('dashboard.max_task_items', 50))
            ->get();
    }

    /**
     * @param  array<string, true>  $appointmentProfiles
     * @return Collection<int, Appointment>
     */
    private function appointments(Workspace $workspace, array $appointmentProfiles): Collection
    {
        if ($appointmentProfiles === []) {
            return collect();
        }

        // Include today's already-passed planned visits: they still need a
        // confirmation decision even though they are no longer "upcoming".
        return Appointment::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('profile_id', array_keys($appointmentProfiles))
            ->where('scheduled_at', '>=', now()->startOfDay())
            ->whereIn('status', [Appointment::STATUS_PLANNED, Appointment::STATUS_CONFIRMED])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    /**
     * @param  Collection<int, HealthProfile>  $profiles
     * @param  Collection<int, HealthMeasurement>  $measurements
     * @param  array<string, true>  $allowedProfiles
     * @return list<array<string, mixed>>
     */
    private function vitals(Collection $profiles, Collection $measurements, array $allowedProfiles): array
    {
        $byProfileType = [];

        foreach ($measurements as $measurement) {
            $byProfileType[(string) $measurement->profile_id][(string) $measurement->type][] = $measurement;
        }

        $cards = [];

        // Deterministic order: profile order, then the measurement-type enum.
        foreach ($profiles as $profile) {
            if (! isset($allowedProfiles[(string) $profile->id])) {
                continue;
            }

            foreach (MeasurementType::values() as $type) {
                $rows = $byProfileType[(string) $profile->id][$type] ?? [];

                if ($rows === []) {
                    continue;
                }

                /** @var HealthMeasurement $reading */
                $reading = $rows[array_key_last($rows)];
                $status = VitalStatus::classify(
                    $type,
                    (float) $reading->value,
                    $reading->secondary_value === null ? null : (float) $reading->secondary_value,
                );

                $values = array_map(static fn (HealthMeasurement $row): float => (float) $row->value, $rows);

                $cards[] = [
                    'type' => $type,
                    // Added to the contract sample because one response may
                    // cover several profiles when `profile_id` is omitted.
                    'person_id' => (string) $profile->person_id,
                    'status' => $status->status,
                    'latest' => [
                        'value' => (float) $reading->value,
                        'secondary_value' => $reading->secondary_value === null ? null : (float) $reading->secondary_value,
                        'unit' => $reading->unit,
                        'recorded_at' => $reading->recorded_at?->toIso8601String(),
                    ],
                    'trend_7d' => [
                        'count' => count($values),
                        'min' => min($values),
                        'max' => max($values),
                        'avg' => round(array_sum($values) / count($values), 1),
                    ],
                ];
            }
        }

        return $cards;
    }

    /**
     * @param  Collection<int, HealthProfile>  $profiles
     * @param  array<string, array<string, true>>  $allowed
     * @param  Collection<int, HealthMeasurement>  $measurements
     * @param  Collection<int, CareTask>  $tasks
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, array{status: string, reason: string, ring: string}>
     */
    private function rings(
        Workspace $workspace,
        User $actor,
        Collection $profiles,
        array $allowed,
        Collection $measurements,
        Collection $tasks,
        Collection $appointments,
    ): array {
        $familyProfiles = $allowed['person.read'] ?? [];

        if ($familyProfiles === []) {
            return [];
        }

        // The actor's ring toward each profile is social context only; it never
        // grants access (F-04). One query, regardless of profile count.
        $relationships = CareRelationship::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('profile_id', array_keys($familyProfiles))
            ->where('status', 'active')
            ->whereHas('person', fn (Builder $person) => $person->where('user_id', $actor->id))
            ->get()
            ->keyBy('profile_id');

        $recentByType = [];
        $windowHours = (int) config('dashboard.alert_window_hours', 24);

        foreach ($measurements as $measurement) {
            if ($measurement->recorded_at !== null && $measurement->recorded_at->greaterThanOrEqualTo(now()->subHours($windowHours))) {
                $recentByType[(string) $measurement->profile_id][(string) $measurement->type][] = $measurement;
            }
        }

        $tasksByProfile = $tasks->groupBy(fn (CareTask $task): string => (string) $task->profile_id);
        $appointmentsByProfile = $appointments->groupBy(fn (Appointment $appointment): string => (string) $appointment->profile_id);

        $rings = [];

        foreach ($profiles as $profile) {
            $profileId = (string) $profile->id;

            if (! isset($familyProfiles[$profileId])) {
                continue;
            }

            $status = 'green';
            $reason = 'stable';

            $criticalVital = false;
            $warningVital = false;

            foreach ($recentByType[$profileId] ?? [] as $type => $rows) {
                /** @var HealthMeasurement $row */
                $row = $rows[array_key_last($rows)];
                $vital = VitalStatus::classify(
                    (string) $type,
                    (float) $row->value,
                    $row->secondary_value === null ? null : (float) $row->secondary_value,
                );

                if ($vital->isCritical()) {
                    $criticalVital = true;
                } elseif ($vital->isWarning()) {
                    $warningVital = true;
                }
            }

            $overdueClinical = ($tasksByProfile[$profileId] ?? collect())
                ->contains(fn (CareTask $task): bool => $this->isOverdue($task)
                    && $task->tier === CareTask::TIER_CLINICAL);

            $pendingTasks = ($tasksByProfile[$profileId] ?? collect())
                ->contains(fn (CareTask $task): bool => ! CareTask::isTerminal((string) $task->status));

            $needsConfirmation = ($appointmentsByProfile[$profileId] ?? collect())
                ->contains(fn (Appointment $appointment): bool => $this->needsConfirmation($appointment));

            if ($criticalVital) {
                [$status, $reason] = ['red', 'critical_vital'];
            } elseif ($overdueClinical) {
                [$status, $reason] = ['red', 'clinical_task_overdue'];
            } elseif ($pendingTasks) {
                [$status, $reason] = ['yellow', 'tasks_pending'];
            } elseif ($warningVital) {
                [$status, $reason] = ['yellow', 'vital_warning'];
            } elseif ($needsConfirmation) {
                [$status, $reason] = ['yellow', 'appointment_confirmation'];
            }

            $ring = (string) $profile->person?->user_id === (string) $actor->id
                ? 'inner'
                : (string) ($relationships[$profileId]->ring ?? 'support');

            $rings[$profileId] = ['status' => $status, 'reason' => $reason, 'ring' => $ring];
        }

        return $rings;
    }

    /**
     * @param  Collection<int, HealthProfile>  $profiles
     * @param  array<string, array{status: string, reason: string, ring: string}>  $rings
     * @param  array<string, true>  $allowedProfiles
     * @return array<string, mixed>
     */
    private function family(Collection $profiles, array $rings, array $allowedProfiles, string $reason): array
    {
        if ($allowedProfiles === []) {
            return ['visible' => false, 'reason' => $reason];
        }

        $members = [];

        foreach ($profiles as $profile) {
            $profileId = (string) $profile->id;

            if (! isset($allowedProfiles[$profileId], $rings[$profileId])) {
                continue;
            }

            $members[] = [
                'person_id' => (string) $profile->person_id,
                'display_name' => (string) ($profile->person?->display_name ?? ''),
                'ring' => $rings[$profileId]['ring'],
                'status' => $rings[$profileId]['status'],
                'reason' => $rings[$profileId]['reason'],
            ];
        }

        usort($members, static function (array $left, array $right): int {
            return [
                self::STATUS_RANK[$left['status']] ?? 9,
                self::RING_RANK[$left['ring']] ?? 9,
                $left['display_name'],
                $left['person_id'],
            ] <=> [
                self::STATUS_RANK[$right['status']] ?? 9,
                self::RING_RANK[$right['ring']] ?? 9,
                $right['display_name'],
                $right['person_id'],
            ];
        });

        return ['visible' => true, 'members' => $members];
    }

    /**
     * @param  Collection<int, HealthProfile>  $profiles
     * @param  Collection<int, HealthMeasurement>  $measurements
     * @param  Collection<int, CareTask>  $tasks
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function alerts(
        Collection $profiles,
        Collection $measurements,
        Collection $tasks,
        Collection $appointments,
    ): array {
        $personByProfile = $profiles->mapWithKeys(
            fn (HealthProfile $profile): array => [(string) $profile->id => (string) $profile->person_id],
        )->all();

        $alerts = [];

        // Latest reading per profile/type inside the alert window.
        $latest = [];
        $windowHours = (int) config('dashboard.alert_window_hours', 24);

        foreach ($measurements as $measurement) {
            if ($measurement->recorded_at === null || $measurement->recorded_at->lessThan(now()->subHours($windowHours))) {
                continue;
            }

            $latest[(string) $measurement->profile_id][(string) $measurement->type] = $measurement;
        }

        foreach ($latest as $profileId => $types) {
            foreach ($types as $type => $reading) {
                $vital = VitalStatus::classify(
                    (string) $type,
                    (float) $reading->value,
                    $reading->secondary_value === null ? null : (float) $reading->secondary_value,
                );

                if (! $vital->isCritical() && ! $vital->isWarning()) {
                    continue;
                }

                $alerts[] = [
                    'level' => $vital->severity,
                    'person_id' => $personByProfile[$profileId] ?? null,
                    'code' => 'vital_high',
                    'task_id' => null,
                    'message_key' => match (true) {
                        $vital->isCritical() => 'dashboard.alert.vital_critical',
                        $vital->direction === 'low' => 'dashboard.alert.vital_low',
                        default => 'dashboard.alert.vital_high',
                    },
                ];
            }
        }

        foreach ($tasks as $task) {
            if (! $this->isOverdue($task)) {
                continue;
            }

            $alerts[] = [
                'level' => $task->tier === CareTask::TIER_CLINICAL ? 'critical' : 'warning',
                'person_id' => $personByProfile[(string) $task->profile_id] ?? null,
                'code' => 'tasks_overdue',
                'task_id' => (string) $task->id,
                'message_key' => 'dashboard.alert.tasks_overdue',
            ];
        }

        $seenConfirmation = [];

        foreach ($appointments as $appointment) {
            $profileId = (string) $appointment->profile_id;

            if (isset($seenConfirmation[$profileId]) || ! $this->needsConfirmation($appointment)) {
                continue;
            }

            $seenConfirmation[$profileId] = true;

            $alerts[] = [
                'level' => 'warning',
                'person_id' => $personByProfile[$profileId] ?? null,
                'code' => 'appointment_soon',
                'task_id' => null,
                'message_key' => 'dashboard.alert.appointment_soon',
            ];
        }

        usort($alerts, static function (array $left, array $right): int {
            $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return [
                $rank[$left['level']] ?? 9,
                $left['code'],
                (string) $left['person_id'],
                (string) ($left['task_id'] ?? ''),
            ] <=> [
                $rank[$right['level']] ?? 9,
                $right['code'],
                (string) $right['person_id'],
                (string) ($right['task_id'] ?? ''),
            ];
        });

        return $alerts;
    }

    /**
     * @param  Collection<int, CareTask>  $tasks
     * @return array<string, mixed>
     */
    private function tasksToday(Collection $tasks, bool $visible): array
    {
        $done = $tasks->filter(fn (CareTask $task): bool => $task->status === CareTask::STATUS_COMPLETED)->count();
        $overdue = $tasks->filter(fn (CareTask $task): bool => $this->isOverdue($task))->count();

        $items = $tasks
            ->take((int) config('dashboard.max_task_items', 50))
            ->map(static fn (CareTask $task): array => (new CareTaskCardResource($task))->resolve())
            ->values()
            ->all();

        return [
            'visible' => $visible,
            'total' => $tasks->count(),
            'done' => $done,
            'overdue' => $overdue,
            'items' => $items,
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, mixed>
     */
    private function nextAppointment(Collection $appointments): array
    {
        $next = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->scheduled_at !== null
                && $appointment->scheduled_at->greaterThanOrEqualTo(now()))
            ->sortBy(fn (Appointment $appointment): string => $appointment->scheduled_at->toIso8601String())
            ->first();

        if ($next === null) {
            return [
                'visible' => true,
                'id' => null,
                'person_id' => null,
                'title' => null,
                'scheduled_at' => null,
                'status' => null,
            ];
        }

        return [
            'visible' => true,
            'id' => (string) $next->id,
            'person_id' => (string) $next->profile_id,
            'title' => (string) $next->title,
            'scheduled_at' => $next->scheduled_at?->toIso8601String(),
            'status' => (string) $next->status,
        ];
    }

    private function isOverdue(CareTask $task): bool
    {
        return $task->due_at !== null
            && $task->due_at->lessThan(now())
            && ! CareTask::isTerminal((string) $task->status);
    }

    private function needsConfirmation(Appointment $appointment): bool
    {
        return $appointment->status === Appointment::STATUS_PLANNED
            && $appointment->scheduled_at !== null
            && $appointment->scheduled_at->between(now()->startOfDay(), now()->endOfDay());
    }

    private function cacheKey(Workspace $workspace, User $actor, ?string $profileId, int $authzVersion): string
    {
        return implode(':', [
            'dashboard',
            (string) $workspace->tenant_id,
            (string) $workspace->id,
            (string) $actor->id,
            $profileId ?? 'all',
            (string) $authzVersion,
        ]);
    }
}
