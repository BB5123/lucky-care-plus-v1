<?php

namespace App\Support\Performance;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\HealthRecord\Models\MedicationRecord;
use App\Modules\People\Models\Person;
use App\Modules\Timeline\Application\TimelineSource;
use App\Modules\Timeline\Domain\TimelineProjection;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * Synthetic fixture for `perf:smoke`.
 *
 * The fixture lives in its own tenant (`perf-smoke`) and a clearly marked
 * workspace so it can never be mistaken for real family data. It is created
 * once and then reused idempotently: every row has a deterministic UUID, so a
 * second run inserts nothing (the source rows and the timeline projection are
 * written with `insertOrIgnore`). Request-time audit rows are append-only by
 * design and therefore accumulate; they are reported separately from the
 * fixture row counts.
 *
 * Scale:
 *  - `full`     500 health measurements / 120 care tasks / 60 medication
 *               records / 12 appointments / 200 audit rows.
 *  - `compact`  a test-sized slice of the same shape, selected automatically
 *               when the command runs under PHPUnit.
 *
 * Everything in this class is fixture scaffolding: no health value is read by
 * the measured requests from here, and no free-text health field is written.
 */
final class PerfFixture
{
    public const TENANT_SLUG = 'perf-smoke';

    public const TENANT_NAME = '[perf:smoke] Synthetic Tenant';

    public const WORKSPACE_SLUG = 'perf-smoke-fixture';

    public const WORKSPACE_NAME = '[perf:smoke] Synthetic Workspace';

    public const USER_EMAIL = 'perf-smoke@example.invalid';

    public const TOKEN_NAME = 'perf:smoke';

    public const FIXTURE_VERSION = 1;

    private const ID_PREFIX = '0198f000-0000-7000-8000-';

    private ?Tenant $tenant = null;

    private ?Workspace $workspace = null;

    private ?User $actor = null;

    private ?HealthProfile $profile = null;

    private string $scale = 'full';

    public function __construct(private readonly SecurityAuditLogger $audit) {}

    /**
     * Create or reuse the fixture and return the ids the runner needs.
     *
     * @return array{tenant_id: string, workspace_id: string, profile_id: string, user_id: int, created: bool}
     */
    public function ensure(bool $compact): array
    {
        $this->scale = $compact ? 'compact' : 'full';

        $this->tenant = $this->ensureTenant();
        $this->workspace = $this->ensureWorkspace($this->tenant);

        /** @var array{tenant_id: string, workspace_id: string, profile_id: string, user_id: int, created: bool} $info */
        $info = TenantContext::run(
            $this->tenant->id,
            $this->workspace->id,
            function () use ($compact): array {
                $this->actor = $this->ensureUser();
                $this->ensureMembership();
                $this->ensurePersonAndProfile();

                $markerVersion = (int) ($this->workspace?->settings['fixture_version'] ?? 0);
                $markerScale = $this->workspace?->settings['fixture_scale'] ?? null;

                // A full fixture also satisfies a compact request (the compact
                // ids are a prefix of the full ids). The other direction needs
                // a seeding pass to extend the fixture to full size.
                $reuse = $markerVersion === self::FIXTURE_VERSION
                    && (is_string($markerScale) && ($markerScale === $this->scale || $markerScale === 'full'));

                if ($reuse) {
                    $this->scale = (string) $markerScale;
                    $this->refreshOperationalClock();
                } else {
                    $this->seed($compact);
                    $this->markSeeded();
                }

                return [
                    'tenant_id' => (string) $this->tenant?->id,
                    'workspace_id' => (string) $this->workspace?->id,
                    'profile_id' => (string) $this->profile?->id,
                    'user_id' => (int) $this->actor?->id,
                    'created' => ! $reuse,
                ];
            },
        );

        return $info;
    }

    public function scale(): string
    {
        return $this->scale;
    }

    public function workspaceId(): string
    {
        return (string) $this->workspace?->id;
    }

    /**
     * Issue a short-lived bearer token for the fixture owner.
     *
     * Previous perf tokens are revoked so repeated runs do not leave a growing
     * token table behind. The plain-text value exists only in this process.
     */
    public function issueToken(): string
    {
        if ($this->actor === null) {
            throw new RuntimeException('The perf fixture owner is not initialised.');
        }

        $this->actor->tokens()->where('name', self::TOKEN_NAME)->delete();

        return $this->actor->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    /**
     * Row counts scoped to the fixture workspace.
     *
     * `audit_rows` is deliberately separate: append-only request audit rows
     * make that number grow on every run, while the fixture sources stay
     * stable (PRF-T03).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        if ($this->tenant === null || $this->workspace === null) {
            throw new RuntimeException('The perf fixture is not initialised.');
        }

        $workspaceId = $this->workspace->id;

        return TenantContext::run($this->tenant->id, $workspaceId, fn (): array => [
            'measurements' => DB::table('health_measurements')->where('workspace_id', $workspaceId)->count(),
            'care_tasks' => DB::table('care_tasks')->where('workspace_id', $workspaceId)->count(),
            'task_events' => DB::table('task_events')->where('workspace_id', $workspaceId)->count(),
            'medication_plans' => DB::table('medication_plans')->where('workspace_id', $workspaceId)->count(),
            'medication_records' => DB::table('medication_records')->where('workspace_id', $workspaceId)->count(),
            'appointments' => DB::table('appointments')->where('workspace_id', $workspaceId)->count(),
            'timeline_entries' => DB::table('timeline_entries')->where('workspace_id', $workspaceId)->count(),
            'audit_rows' => DB::table('security_audit_logs')->where('workspace_id', $workspaceId)->count(),
        ]);
    }

    private function ensureTenant(): Tenant
    {
        $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->first();

        if ($tenant === null) {
            $tenant = new Tenant([
                'name' => self::TENANT_NAME,
                'slug' => self::TENANT_SLUG,
            ]);
            $tenant->id = self::id(1);
            $tenant->save();

            return $tenant;
        }

        if ($tenant->name !== self::TENANT_NAME) {
            throw new RuntimeException(sprintf(
                'Refusing to use tenant slug [%s]: it does not belong to perf:smoke.',
                self::TENANT_SLUG,
            ));
        }

        return $tenant;
    }

    private function ensureWorkspace(Tenant $tenant): Workspace
    {
        $workspace = TenantContext::run(
            $tenant->id,
            '',
            fn (): ?Workspace => Workspace::query()->where('slug', self::WORKSPACE_SLUG)->first(),
        );

        if ($workspace === null) {
            $workspace = new Workspace([
                'tenant_id' => $tenant->id,
                'name' => self::WORKSPACE_NAME,
                'slug' => self::WORKSPACE_SLUG,
                'settings' => ['perf_smoke' => true],
                'break_glass_enabled' => false,
            ]);
            $workspace->id = self::id(2);

            TenantContext::run($tenant->id, $workspace->id, fn () => $workspace->save());

            return $workspace;
        }

        if (($workspace->settings['perf_smoke'] ?? false) !== true) {
            throw new RuntimeException(sprintf(
                'Refusing to use workspace [%s]: the perf:smoke marker is missing.',
                self::WORKSPACE_SLUG,
            ));
        }

        return $workspace;
    }

    private function ensureUser(): User
    {
        $user = User::query()->where('email', self::USER_EMAIL)->first();

        if ($user === null) {
            $user = new User([
                'name' => 'Perf Smoke Owner',
                'email' => self::USER_EMAIL,
                'password' => 'perf-smoke-fixture-only',
            ]);
            $user->email_verified_at = now();
            $user->save();
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    private function ensureMembership(): void
    {
        if ($this->workspace === null || $this->actor === null) {
            throw new RuntimeException('The perf fixture workspace is not initialised.');
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('user_id', $this->actor->id)
            ->first();

        if ($membership === null) {
            $membership = new WorkspaceMembership([
                'tenant_id' => $this->workspace->tenant_id,
                'workspace_id' => $this->workspace->id,
                'user_id' => $this->actor->id,
                'role' => 'profile_owner',
                'status' => 'active',
            ]);
            $membership->id = self::id(3);
            $membership->save();

            return;
        }

        if ($membership->role !== 'profile_owner' || $membership->status !== 'active') {
            $membership->forceFill(['role' => 'profile_owner', 'status' => 'active'])->save();
        }
    }

    private function ensurePersonAndProfile(): void
    {
        if ($this->workspace === null || $this->actor === null) {
            throw new RuntimeException('The perf fixture workspace is not initialised.');
        }

        $person = Person::query()->where('id', self::id(4))->first();

        if ($person === null) {
            $person = new Person([
                'tenant_id' => $this->workspace->tenant_id,
                'workspace_id' => $this->workspace->id,
                'user_id' => $this->actor->id,
                'display_name' => 'Perf Smoke Person',
            ]);
            $person->id = self::id(4);
            $person->save();
        }

        $profile = HealthProfile::query()->where('person_id', $person->id)->first();

        if ($profile === null) {
            $profile = new HealthProfile([
                'tenant_id' => $this->workspace->tenant_id,
                'workspace_id' => $this->workspace->id,
                'person_id' => $person->id,
                'version' => 1,
            ]);
            $profile->id = self::id(5);
            $profile->save();
        }

        $this->profile = $profile;
    }

    private function seed(bool $compact): void
    {
        if ($this->workspace === null || $this->actor === null || $this->profile === null) {
            throw new RuntimeException('The perf fixture is not initialised.');
        }

        $timeline = [];
        $now = now();

        $this->seedMeasurements($compact, $now, $timeline);
        $this->seedCareTasks($compact, $now, $timeline);
        $this->seedMedications($compact, $now, $timeline);
        $this->seedAppointments($compact, $now, $timeline);

        $this->insertRows('timeline_entries', $timeline);
        $this->seedAuditRows($compact, $now);
    }

    /**
     * @param  list<array<string, mixed>>  $timeline
     */
    private function seedMeasurements(bool $compact, Carbon $now, array &$timeline): void
    {
        $count = $compact ? 60 : 500;
        $types = ['blood_pressure', 'heart_rate', 'spo2', 'blood_glucose', 'weight', 'temperature'];
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $id = self::id(100000 + $i);
            $type = $types[$i % count($types)];
            // Spread evenly over the last 24 months, oldest first.
            $recordedAt = $now->copy()->subMinutes((int) round(
                730 * 24 * 60 * (1 - ($count === 1 ? 1 : $i / ($count - 1))),
            ));

            [$value, $secondary, $unit, $context] = $this->measurementValues($type, $i);

            $rows[] = [
                'id' => $id,
                'tenant_id' => $this->workspace?->tenant_id,
                'workspace_id' => $this->workspace?->id,
                'profile_id' => $this->profile?->id,
                'type' => $type,
                'value' => $value,
                'secondary_value' => $secondary,
                'unit' => $unit,
                'context' => $context,
                'note' => null,
                'recorded_at' => $recordedAt,
                'recorded_by' => $this->actor?->id,
                'source' => 'manual',
                'version' => 1,
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
            ];

            $source = new HealthMeasurement;
            $source->id = $id;
            $source->type = $type;
            $source->recorded_at = $recordedAt;
            $source->created_at = $recordedAt;

            $timeline[] = $this->timelineRow(
                self::id(700000 + $i),
                TimelineSource::forMeasurement($source)[0],
            );
        }

        $this->insertRows('health_measurements', $rows);
    }

    /**
     * @return array{0: float, 1: ?float, 2: string, 3: ?string}
     */
    private function measurementValues(string $type, int $i): array
    {
        $critical = $i % 97 === 0;

        return match ($type) {
            'blood_pressure' => [
                $critical ? 178.0 : (float) (112 + ($i % 42)),
                $critical ? 108.0 : (float) (68 + ($i % 22)),
                'mmHg',
                null,
            ],
            'heart_rate' => [$critical ? 142.0 : (float) (58 + ($i % 48)), null, 'bpm', null],
            'spo2' => [(float) (94 + ($i % 7)), null, '%', null],
            'blood_glucose' => [(float) (86 + ($i % 126)), null, 'mg/dL', 'random'],
            'weight' => [55.0 + (($i % 300) / 10), null, 'kg', null],
            default => [36.0 + (($i % 10) / 10), null, '°C', null],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $timeline
     */
    private function seedCareTasks(bool $compact, Carbon $now, array &$timeline): void
    {
        $count = $compact ? 24 : 120;
        $statuses = [
            CareTask::STATUS_SCHEDULED,
            CareTask::STATUS_OPEN,
            CareTask::STATUS_ASSIGNED,
            CareTask::STATUS_ACCEPTED,
            CareTask::STATUS_IN_PROGRESS,
            CareTask::STATUS_BLOCKED,
            CareTask::STATUS_COMPLETED,
            CareTask::STATUS_CANCELLED,
        ];
        $tiers = [CareTask::TIER_ROUTINE, CareTask::TIER_DELEGATED, CareTask::TIER_CLINICAL];
        $types = [
            'MEDICATION_SUPPORT',
            'VITAL_MEASUREMENT',
            'APPOINTMENT',
            'DAILY_CARE',
            'FOLLOW_UP',
            'CUSTOM',
        ];

        $tasks = [];
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $taskId = self::id(200000 + $i);
            $status = $statuses[$i % count($statuses)];
            $tier = $tiers[$i % count($tiers)];
            $type = $types[$i % count($types)];
            $createdAt = $now->copy()->subDays(90 - ($i % 60));
            $dueAt = $this->taskDueAt($i, $status, $now);
            $completed = $status === CareTask::STATUS_COMPLETED;
            $cancelled = $status === CareTask::STATUS_CANCELLED;

            $tasks[] = [
                'id' => $taskId,
                'tenant_id' => $this->workspace?->tenant_id,
                'workspace_id' => $this->workspace?->id,
                'profile_id' => $this->profile?->id,
                'created_by' => $this->actor?->id,
                'template_id' => null,
                'tier' => $tier,
                'type' => $type,
                'title' => 'Perf care task '.($i + 1),
                'description' => null,
                'priority' => $i % 5 === 0 ? 'high' : 'normal',
                'scheduled_for' => $dueAt->copy()->subHours(2),
                'due_at' => $dueAt,
                'timezone' => 'Asia/Bangkok',
                'status' => $status,
                'outcome' => $completed ? CareTask::OUTCOME_DONE : null,
                'verification' => $tier === CareTask::TIER_CLINICAL
                    ? ($completed ? CareTask::VERIFICATION_VERIFIED : CareTask::VERIFICATION_PENDING)
                    : CareTask::VERIFICATION_NOT_REQUIRED,
                'verification_required' => $tier === CareTask::TIER_CLINICAL,
                'evidence_policy' => $tier === CareTask::TIER_CLINICAL
                    ? CareTask::EVIDENCE_VALUE
                    : CareTask::EVIDENCE_NONE,
                'escalation_level' => $status === CareTask::STATUS_BLOCKED ? 1 : 0,
                'blocked_reason' => $status === CareTask::STATUS_BLOCKED ? 'Awaiting a clinic reply' : null,
                'review_at' => null,
                'completed_at' => $completed ? $dueAt->copy()->addHour() : null,
                'idempotency_key' => 'perf-task-'.$i,
                'version' => 1 + ($i % 3),
                'source' => 'manual',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'deleted_at' => null,
            ];

            $initialStatus = CareTask::STATUS_OPEN;
            $eventSpecs = [
                ['event' => 'created', 'from' => null, 'to' => $initialStatus, 'at' => $createdAt],
                [
                    'event' => $completed
                        ? 'completed'
                        : ($cancelled ? 'cancelled' : ($status === CareTask::STATUS_BLOCKED ? 'blocked' : 'updated')),
                    'from' => $initialStatus,
                    'to' => $status,
                    'at' => $completed || $cancelled ? $dueAt->copy()->addHour() : $dueAt,
                ],
            ];

            foreach ($eventSpecs as $offset => $spec) {
                $eventId = self::id(300000 + ($i * 2) + $offset);

                $events[] = [
                    'id' => $eventId,
                    'tenant_id' => $this->workspace?->tenant_id,
                    'workspace_id' => $this->workspace?->id,
                    'task_id' => $taskId,
                    'actor_id' => $this->actor?->id,
                    'actor_type' => 'user',
                    'from_status' => $spec['from'],
                    'to_status' => $spec['to'],
                    'event' => $spec['event'],
                    'reason' => null,
                    'metadata' => '{}',
                    'occurred_at' => $spec['at'],
                    'created_at' => $spec['at'],
                ];

                $source = new TaskEvent;
                $source->id = $eventId;
                $source->event = $spec['event'];
                $source->from_status = $spec['from'];
                $source->to_status = $spec['to'];
                $source->occurred_at = $spec['at'];
                $source->created_at = $spec['at'];

                $timeline[] = $this->timelineRow(
                    self::id(800000 + ($i * 2) + $offset),
                    TimelineSource::forTaskEvent($source, new CareTask)[0],
                );
            }
        }

        $this->insertRows('care_tasks', $tasks);
        $this->insertRows('task_events', $events);
    }

    private function taskDueAt(int $index, string $status, Carbon $now): Carbon
    {
        if (in_array($status, [CareTask::STATUS_COMPLETED, CareTask::STATUS_CANCELLED], true)) {
            return $now->copy()->subDays(($index % 45) + 1);
        }

        // Some tasks are overdue, some are due today, some are upcoming. This
        // makes the dashboard's "today" card and the overdue alert real.
        return $now->copy()->addHours((($index % 11) - 5) * 12);
    }

    /**
     * @param  list<array<string, mixed>>  $timeline
     */
    private function seedMedications(bool $compact, Carbon $now, array &$timeline): void
    {
        $planCount = $compact ? 3 : 6;
        $recordCount = $compact ? 12 : 60;
        $forms = ['tablet', 'capsule', 'liquid'];
        $plans = [];
        $records = [];

        for ($i = 0; $i < $planCount; $i++) {
            $planId = self::id(400000 + $i);
            $startedAt = $now->copy()->subMonths(18 - ($i * 2));
            $doses = $i % 2 === 0 ? 1 : 2;
            $times = $doses === 1 ? ['08:00'] : ['08:00', '20:00'];

            $plans[] = [
                'id' => $planId,
                'tenant_id' => $this->workspace?->tenant_id,
                'workspace_id' => $this->workspace?->id,
                'profile_id' => $this->profile?->id,
                'recorded_by' => $this->actor?->id,
                'source' => 'manual',
                'name' => 'Perf medication '.($i + 1),
                'dosage' => $doses === 1 ? '5 mg' : '2.5 mg',
                'form' => $forms[$i % count($forms)],
                'route' => 'oral',
                'doses_per_day' => $doses,
                'schedule_times' => json_encode($times, JSON_THROW_ON_ERROR),
                'starts_on' => $startedAt->toDateString(),
                'ends_on' => null,
                'prescribed_by' => null,
                'instructions' => null,
                'status' => MedicationPlan::STATUS_ACTIVE,
                'discontinued_reason' => null,
                'discontinued_at' => null,
                'version' => 1,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
                'deleted_at' => null,
            ];

            $source = new MedicationPlan;
            $source->id = $planId;
            $source->created_at = $startedAt;
            $source->status = MedicationPlan::STATUS_ACTIVE;

            $timeline[] = $this->timelineRow(
                self::id(900000 + $i),
                TimelineSource::medicationPlanCreated($source),
            );
        }

        $statuses = [
            MedicationRecord::STATUS_TAKEN,
            MedicationRecord::STATUS_TAKEN,
            MedicationRecord::STATUS_SKIPPED,
            MedicationRecord::STATUS_MISSED,
        ];
        $planIds = array_column($plans, 'id');

        for ($i = 0; $i < $recordCount; $i++) {
            $id = self::id(500000 + $i);
            $status = $statuses[$i % count($statuses)];
            $recordedAt = $now->copy()->subDays(364 - (int) round(360 * ($i / max(1, $recordCount - 1))));

            $records[] = [
                'id' => $id,
                'tenant_id' => $this->workspace?->tenant_id,
                'workspace_id' => $this->workspace?->id,
                'profile_id' => $this->profile?->id,
                'plan_id' => $planIds[$i % count($planIds)],
                'recorded_by' => $this->actor?->id,
                'source' => 'manual',
                'status' => $status,
                'scheduled_for' => null,
                'recorded_at' => $recordedAt,
                'note' => null,
                'version' => 1,
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
                'deleted_at' => null,
            ];

            $source = new MedicationRecord;
            $source->id = $id;
            $source->status = $status;
            $source->recorded_at = $recordedAt;
            $source->created_at = $recordedAt;

            $timeline[] = $this->timelineRow(
                self::id(910000 + $i),
                TimelineSource::forMedicationRecord($source)[0],
            );
        }

        $this->insertRows('medication_plans', $plans);
        $this->insertRows('medication_records', $records);
    }

    /**
     * @param  list<array<string, mixed>>  $timeline
     */
    private function seedAppointments(bool $compact, Carbon $now, array &$timeline): void
    {
        $count = $compact ? 6 : 12;
        $statuses = [
            Appointment::STATUS_PLANNED,
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_ATTENDED,
            Appointment::STATUS_CANCELLED,
        ];
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $id = self::id(600000 + $i);
            $status = $statuses[$i % count($statuses)];
            $createdAt = $now->copy()->subDays(120 - ($i * 5));
            $scheduledAt = $now->copy()->addDays(($i % 10) - 3);

            $rows[] = [
                'id' => $id,
                'tenant_id' => $this->workspace?->tenant_id,
                'workspace_id' => $this->workspace?->id,
                'profile_id' => $this->profile?->id,
                'created_by' => $this->actor?->id,
                'source' => 'manual',
                'title' => 'Perf appointment '.($i + 1),
                'provider_name' => null,
                'facility' => null,
                'purpose' => null,
                'location' => null,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => 30,
                'status' => $status,
                'cancelled_reason' => null,
                'rescheduled_from_id' => null,
                'transported_by_task_id' => null,
                'prepared_documents' => '[]',
                'follow_up_task_id' => null,
                'version' => 1,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'deleted_at' => null,
            ];

            $source = new Appointment;
            $source->id = $id;
            $source->status = $status;
            $source->created_at = $createdAt;
            $source->updated_at = $scheduledAt;
            $source->scheduled_at = $scheduledAt;

            foreach (TimelineSource::forAppointment($source) as $offset => $projection) {
                $timeline[] = $this->timelineRow(
                    self::id(920000 + ($i * 2) + $offset),
                    $projection,
                );
            }
        }

        $this->insertRows('appointments', $rows);
    }

    private function seedAuditRows(bool $compact, Carbon $now): void
    {
        if ($this->workspace === null || $this->actor === null || $this->profile === null) {
            return;
        }

        // The ledger is append-only, so the fixture uses deterministic ids and
        // adds only the rows that are still missing. This also lets a compact
        // fixture be extended to full size without duplicating the ledger.
        $count = $compact ? 20 : 200;
        $events = [];
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = self::id(950000 + $i);
        }

        $existing = DB::table('security_audit_logs')->whereIn('id', $ids)->pluck('id')->all();
        $existing = array_fill_keys(array_map('strval', $existing), true);

        for ($i = 0; $i < $count; $i++) {
            $eventId = self::id(950000 + $i);

            if (isset($existing[$eventId])) {
                continue;
            }

            $events[] = [
                'id' => $eventId,
                'occurred_at' => $now->copy()->subDays(730 - (int) round(720 * ($i / max(1, $count - 1)))),
                'actor_id' => $this->actor->id,
                'actor_type' => 'user',
                'action' => 'health_measurement.read',
                'target_type' => 'health_profile',
                'target_id' => $this->profile->id,
                'result' => 'allow',
                'reason_code' => null,
                'metadata' => ['fixture' => 'perf-smoke', 'sequence' => $i],
            ];
        }

        $this->audit->recordMany($events);
    }

    /**
     * Move "today" back to the current wall clock when the fixture is reused.
     * Only scheduling columns change; row counts stay identical.
     */
    private function refreshOperationalClock(): void
    {
        if ($this->workspace === null) {
            return;
        }

        $now = now();

        $tasks = DB::table('care_tasks')
            ->where('workspace_id', $this->workspace->id)
            ->orderBy('id')
            ->get(['id', 'status']);

        foreach ($tasks as $index => $task) {
            DB::table('care_tasks')
                ->where('id', $task->id)
                ->where('workspace_id', $this->workspace->id)
                ->update([
                    'due_at' => $this->taskDueAt($index, (string) $task->status, $now),
                    'updated_at' => $now,
                ]);
        }

        $appointments = DB::table('appointments')
            ->where('workspace_id', $this->workspace->id)
            ->orderBy('id')
            ->pluck('id');

        foreach ($appointments as $index => $appointmentId) {
            DB::table('appointments')
                ->where('id', $appointmentId)
                ->where('workspace_id', $this->workspace->id)
                ->update([
                    'scheduled_at' => $now->copy()->addDays(($index % 10) - 3),
                    'updated_at' => $now,
                ]);
        }
    }

    private function markSeeded(): void
    {
        if ($this->workspace === null) {
            return;
        }

        $settings = $this->workspace->settings ?? [];
        $settings['perf_smoke'] = true;
        $settings['fixture_version'] = self::FIXTURE_VERSION;
        $settings['fixture_scale'] = $this->scale;
        $settings['seeded_at'] = now()->toIso8601String();

        $this->workspace->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function timelineRow(string $id, TimelineProjection $projection): array
    {
        $metadata = $projection->metadata === []
            ? new stdClass
            : $projection->metadata;

        return [
            'id' => $id,
            'tenant_id' => $this->workspace?->tenant_id,
            'workspace_id' => $this->workspace?->id,
            'profile_id' => $this->profile?->id,
            'occurred_at' => $projection->occurredAt,
            'type' => $projection->type,
            'ref_type' => $projection->refType,
            'ref_id' => $projection->refId,
            'summary_key' => $projection->summaryKey,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertRows(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk);
        }
    }

    private static function id(int $sequence): string
    {
        return self::ID_PREFIX.sprintf('%012d', $sequence);
    }
}
