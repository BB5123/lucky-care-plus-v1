<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\CareCrm\Application\CareTaskService;
use App\Modules\CareCrm\Application\CareTaskTransitionService;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Application\AllergyService;
use App\Modules\HealthRecord\Application\HealthConditionService;
use App\Modules\HealthRecord\Application\HealthMeasurementService;
use App\Modules\HealthRecord\Application\MedicationPlanService;
use App\Modules\HealthRecord\Application\MedicationRecordService;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\ContactPoint;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Create a realistic, obviously synthetic family for demos and pilot walkthroughs.
 *
 * Design rules (TASK DEPLOY-2):
 *  - every person, email and phone number is deliberately fake (`example.test`,
 *    `080000000x`, Thai names containing "ทดสอบ");
 *  - it refuses to run with `APP_ENV=production`;
 *  - it never calls an external service and never writes a real file (the
 *    three medical documents are metadata only);
 *  - it is idempotent: the workspace is marked in `settings`, and a second run
 *    reports the existing rows instead of inserting duplicates;
 *  - `--reset` only removes mutable rows inside the marked demo workspace. The
 *    append-only `audit_events`, `security_audit_logs` and `consent_records`
 *    ledgers are preserved on purpose, because deleting them is forbidden by
 *    the data model (DM-006 / DM-009 / DM-R05).
 *
 * The demo rows are written through the domain services wherever a service
 * supports the state, so the audit trail and the timeline projection are real,
 * not hand-inserted fixtures.
 */
final class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed
        {--reset : Remove the mutable demo rows and seed them again}
        {--force : Skip the --reset confirmation (for automation)}
        {--profile=elder : Demo persona to seed (only "elder" is supported)}';

    protected $description = 'Create a synthetic demo family (refuses to run in production)';

    /** Bump when the demo shape changes; an older workspace is rebuilt in place. */
    private const VERSION = 1;

    private const PROFILE = 'elder';

    /**
     * The known demo password. It is a demo credential, not a secret: the
     * accounts live at `example.test` and the command refuses production.
     */
    private const PASSWORD = 'DemoPass!2026';

    private const OWNER_EMAIL = 'demo.owner@example.test';

    private const CAREGIVER_EMAIL = 'demo.caregiver@example.test';

    private const VIEWER_EMAIL = 'demo.viewer@example.test';

    /** Fixed ids are used only when --reset creates the workspace from scratch. */
    private const FIXED_TENANT_ID = '0198f0de-0000-7000-8000-000000000002';

    private const FIXED_WORKSPACE_ID = '0198f0de-0000-7000-8000-000000000001';

    private const FIXED_SLUG = 'demo-elder';

    /** Scopes granted to the owner. Sensitive scopes are deliberately absent. */
    private const OWNER_SCOPES = [
        'profile.basic',
        'vitals',
        'medications',
        'tasks',
        'appointments',
        'documents',
        'timeline',
        'task_evidence',
    ];

    private const FULL_ACTIONS = ['read', 'create', 'update', 'export', 'share'];

    /**
     * The caregiver demonstrably does NOT receive `mental_health` or
     * `lab_results` (ADR-014); those scopes are not even declared in the MVP.
     */
    private const CAREGIVER_SCOPES = ['profile.basic', 'vitals', 'medications', 'tasks'];

    private const CAREGIVER_ACTIONS = ['read', 'create', 'update'];

    private const VIEWER_SCOPES = ['profile.basic'];

    private const VIEWER_ACTIONS = ['read'];

    private HealthMeasurementService $measurements;

    private HealthConditionService $conditions;

    private AllergyService $allergies;

    private MedicationPlanService $plans;

    private MedicationRecordService $medicationRecords;

    private CareTaskService $tasks;

    private CareTaskTransitionService $transitions;

    public function handle(
        HealthMeasurementService $measurements,
        HealthConditionService $conditions,
        AllergyService $allergies,
        MedicationPlanService $plans,
        MedicationRecordService $medicationRecords,
        CareTaskService $tasks,
        CareTaskTransitionService $transitions,
    ): int {
        if ($this->laravel->environment('production') || config('app.env') === 'production') {
            $this->error('demo:seed refuses to run with APP_ENV=production; it writes synthetic health data.');

            return self::FAILURE;
        }

        $profile = (string) $this->option('profile');

        if ($profile !== self::PROFILE) {
            $this->error(sprintf(
                'Unsupported demo profile [%s]. This version ships only --profile=%s.',
                $profile,
                self::PROFILE,
            ));

            return self::FAILURE;
        }

        $this->measurements = $measurements;
        $this->conditions = $conditions;
        $this->allergies = $allergies;
        $this->plans = $plans;
        $this->medicationRecords = $medicationRecords;
        $this->tasks = $tasks;
        $this->transitions = $transitions;

        $reset = (bool) $this->option('reset');

        $existing = $this->findDemoWorkspace();

        if ($reset && ! (bool) $this->option('force')) {
            if (! $this->confirm(
                'This removes the mutable synthetic rows in the demo workspace and seeds them again. '
                .'Append-only consent/audit rows are preserved. Continue?',
                false,
            )) {
                $this->warn('Aborted: nothing was changed. Re-run with --force for automation.');

                return self::FAILURE;
            }
        }

        $tenant = $existing === null
            ? $this->createTenant($reset)
            : Tenant::query()->findOrFail($existing->tenant_id);

        $workspaceId = $existing?->id ?? ($reset ? self::FIXED_WORKSPACE_ID : Uuid7::generate());

        try {
            $report = TenantContext::run($tenant->id, $workspaceId, function () use (
                $tenant,
                $workspaceId,
                $existing,
                $reset,
            ): array {
                $workspace = $this->ensureWorkspace($tenant, $workspaceId, $existing, $reset);

                $needsSeed = $reset || ! $this->isCurrent($workspace);
                $deleted = [];

                if ($needsSeed) {
                    $deleted = $this->deleteDemoRows($workspace);
                    $this->seedFamily($tenant, $workspace);
                    $this->markSeeded($workspace);
                }

                return [
                    'workspace' => $workspace,
                    'state' => $existing === null
                        ? 'created'
                        : ($reset ? 'reset' : ($needsSeed ? 'upgraded' : 'reused')),
                    'deleted' => $deleted,
                ];
            });

            // The timeline is projected in `DB::afterCommit()`, so the counts
            // must be read after the seed transaction has actually committed.
            $report['counts'] = $this->counts($tenant, $report['workspace']);
        } catch (Throwable $exception) {
            // A failed first seed must not leave an empty demo tenant behind.
            if ($tenant->wasRecentlyCreated) {
                try {
                    $tenant->delete();
                } catch (Throwable) {
                    // The workspace transaction already rolled back; a tenant
                    // cleanup failure is reported by the exception below.
                }
            }

            $this->error('demo:seed failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->renderReport($tenant, $report);

        return self::SUCCESS;
    }

    /**
     * Locate the single demo workspace by the marker (not by a naming guess).
     */
    private function findDemoWorkspace(): ?Workspace
    {
        // `workspaces` is under RLS, so it can only be listed from inside the
        // owning tenant's context. `tenants` is the un-scoped directory.
        $tenants = Tenant::query()
            ->where('slug', 'like', 'demo-%')
            ->where('name', 'like', '%DEMO%')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            $workspace = TenantContext::run(
                $tenant->id,
                '',
                fn (): ?Workspace => Workspace::query()
                    ->whereRaw("settings->>'demo_seed' = 'true'")
                    ->where('slug', 'like', 'demo-%')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->first(),
            );

            if ($workspace instanceof Workspace) {
                return $workspace;
            }
        }

        return null;
    }

    private function createTenant(bool $fixed): Tenant
    {
        $slug = $fixed ? self::FIXED_SLUG : 'demo-'.bin2hex(random_bytes(4));
        $id = $fixed ? self::FIXED_TENANT_ID : Uuid7::generate();

        $existing = Tenant::query()->where('slug', $slug)->first();

        if ($existing instanceof Tenant) {
            if (! str_contains($existing->name, 'DEMO')) {
                throw new \RuntimeException("Refusing to reuse tenant [{$slug}]: it is not a demo tenant.");
            }

            return $existing;
        }

        $tenant = new Tenant([
            'name' => '[DEMO] LuckyCare+ Synthetic Tenant',
            'slug' => $slug,
        ]);
        $tenant->id = $id;
        $tenant->save();

        return $tenant;
    }

    private function ensureWorkspace(
        Tenant $tenant,
        string $workspaceId,
        ?Workspace $existing,
        bool $reset,
    ): Workspace {
        $workspace = Workspace::query()->find($workspaceId);

        if ($workspace instanceof Workspace) {
            if (($workspace->settings['demo_seed'] ?? false) !== true) {
                throw new \RuntimeException(
                    "Refusing to use workspace [{$workspaceId}]: the demo marker is missing."
                );
            }

            return $workspace;
        }

        $slug = $reset && $existing === null
            ? self::FIXED_SLUG
            : 'demo-'.bin2hex(random_bytes(4));

        $workspace = new Workspace([
            'tenant_id' => $tenant->id,
            'name' => '[DEMO] ครอบครัวทดสอบ (คุณยาย)',
            'slug' => $slug,
            'settings' => ['demo_seed' => true, 'demo_version' => 0, 'demo_profile' => self::PROFILE],
            'break_glass_enabled' => false,
        ]);
        $workspace->id = $workspaceId;
        $workspace->save();

        return $workspace;
    }

    private function isCurrent(Workspace $workspace): bool
    {
        $settings = $workspace->settings ?? [];

        return ($settings['demo_seed'] ?? false) === true
            && (int) ($settings['demo_version'] ?? 0) === self::VERSION
            && (string) ($settings['demo_profile'] ?? '') === self::PROFILE;
    }

    private function markSeeded(Workspace $workspace): void
    {
        $settings = $workspace->settings ?? [];
        $settings['demo_seed'] = true;
        $settings['demo_version'] = self::VERSION;
        $settings['demo_profile'] = self::PROFILE;
        $settings['demo_seeded_at'] = now()->toIso8601String();

        $workspace->forceFill(['settings' => $settings])->save();
    }

    /**
     * Remove only the mutable rows of this demo workspace.
     *
     * Append-only ledgers (`consent_records`, `audit_events`,
     * `security_audit_logs`) and the workspace/users/persons/profiles are kept:
     * the data model forbids deleting consent/audit history, and the reset must
     * stay possible without disabling triggers.
     *
     * `app.retention_purge` is the documented opt-in that lets the retention
     * job delete `task_events` (and the notification delivery rows); the same
     * narrow window is opened here for the demo's own operational history.
     *
     * @return array<string, int>
     */
    private function deleteDemoRows(Workspace $workspace): array
    {
        $workspaceId = $workspace->id;

        $tables = [
            'report_shares',
            'health_reports',
            'task_evidence',
            'appointments',
            'task_assignments',
            'task_events',
            'care_tasks',
            'care_task_template_exceptions',
            'care_task_templates',
            'escalation_policies',
            'medication_records',
            'medication_plans',
            'health_measurements',
            'health_conditions',
            'allergies',
            'timeline_entries',
            'medical_documents',
            'data_exports',
            'ai_requests',
            'ai_usage_counters',
            'notification_deliveries',
            'access_grants',
        ];

        $deleted = [];

        DB::selectOne('select set_config(?, ?, false)', ['app.retention_purge', 'on']);

        try {
            foreach ($tables as $table) {
                $deleted[$table] = DB::table($table)
                    ->where('workspace_id', $workspaceId)
                    ->delete();
            }
        } finally {
            DB::selectOne('select set_config(?, ?, false)', ['app.retention_purge', '']);
        }

        return $deleted;
    }

    /**
     * Upsert the structural rows and create the story data.
     */
    private function seedFamily(Tenant $tenant, Workspace $workspace): void
    {
        $owner = $this->upsertUser(self::OWNER_EMAIL, 'สมชาย ทดสอบ', '0800000001');
        $caregiver = $this->upsertUser(self::CAREGIVER_EMAIL, 'สมหญิง ทดสอบ', '0800000002');
        $viewer = $this->upsertUser(self::VIEWER_EMAIL, 'สมศักดิ์ ทดสอบ', '0800000003');

        $this->upsertMembership($tenant, $workspace, $owner, 'profile_owner');
        $this->upsertMembership($tenant, $workspace, $caregiver, 'caregiver');
        $this->upsertMembership($tenant, $workspace, $viewer, 'viewer');

        $ownerPerson = $this->upsertPerson($workspace, $owner, 'สมชาย ทดสอบ', '1965-03-12');
        $this->upsertPerson($workspace, $caregiver, 'สมหญิง ทดสอบ', '1970-08-24');
        $this->upsertPerson($workspace, $viewer, 'สมศักดิ์ ทดสอบ', '1975-11-02');

        $elder = $this->upsertPerson(
            $workspace,
            null,
            'คุณยาย ทดสอบ',
            '1948-05-12',
            $ownerPerson->id,
        );

        $this->upsertContactPoint($tenant, $workspace, $elder, 'phone', '0800000000', true);

        $profile = $this->upsertHealthProfile($tenant, $workspace, $elder, $ownerPerson->id);

        $this->seedGrants($tenant, $workspace, $profile, $owner, $caregiver, $viewer);
        $this->seedConsents($tenant, $workspace, $profile, $elder);

        $this->seedMeasurements($profile, $owner);
        $this->seedConditions($profile, $owner);
        $this->seedAllergy($profile, $owner);
        $this->seedMedications($profile, $owner);
        $this->seedAppointments($tenant, $workspace, $profile, $owner);
        $this->seedCareTasks($tenant, $workspace, $profile, $owner, $caregiver);
        $this->seedDocuments($tenant, $workspace, $profile, $owner);
    }

    private function upsertUser(string $email, string $name, string $phone): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'phone_verified_at' => $user->phone_verified_at ?? now(),
            // Re-setting the known demo password keeps the documented login
            // working after a --reset without touching a real account (the
            // address is at example.test and the marker is demo-only).
            'password' => self::PASSWORD,
        ])->save();

        return $user->refresh();
    }

    private function upsertMembership(
        Tenant $tenant,
        Workspace $workspace,
        User $user,
        string $role,
    ): void {
        WorkspaceMembership::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            [
                'tenant_id' => $tenant->id,
                'role' => $role,
                'status' => 'active',
            ],
        );
    }

    private function upsertPerson(
        Workspace $workspace,
        ?User $user,
        string $displayName,
        string $birthDate,
        ?string $managedByPersonId = null,
    ): Person {
        $key = $user instanceof User
            ? ['workspace_id' => $workspace->id, 'user_id' => $user->id]
            : ['workspace_id' => $workspace->id, 'display_name' => $displayName];

        $person = Person::query()->firstOrNew($key);

        $person->fill([
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'display_name' => $displayName,
            'birth_date' => $birthDate,
            'managed_by_person_id' => $managedByPersonId,
        ]);
        $person->save();

        return $person;
    }

    private function upsertContactPoint(
        Tenant $tenant,
        Workspace $workspace,
        Person $person,
        string $type,
        string $value,
        bool $isPrimary,
    ): void {
        ContactPoint::query()->updateOrCreate(
            ['person_id' => $person->id, 'type' => $type],
            [
                'tenant_id' => $tenant->id,
                'workspace_id' => $workspace->id,
                'value' => $value,
                'is_primary' => $isPrimary,
            ],
        );
    }

    private function upsertHealthProfile(
        Tenant $tenant,
        Workspace $workspace,
        Person $person,
        ?string $managedByPersonId,
    ): HealthProfile {
        $profile = HealthProfile::query()->firstOrNew(['person_id' => $person->id]);

        $profile->fill([
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'person_id' => $person->id,
            'managed_by_person_id' => $managedByPersonId,
            'version' => 1,
        ]);
        $profile->save();

        // An archived profile would disappear from the profile picker; a demo
        // reset must always make the persona enterable again.
        $profile->forceFill(['archived_at' => null])->save();

        return $profile;
    }

    private function seedGrants(
        Tenant $tenant,
        Workspace $workspace,
        HealthProfile $profile,
        User $owner,
        User $caregiver,
        User $viewer,
    ): void {
        $this->upsertGrant(
            $tenant,
            $workspace,
            $profile,
            $owner,
            $owner,
            'demo_owner_full',
            self::OWNER_SCOPES,
            self::FULL_ACTIONS,
        );

        $this->upsertGrant(
            $tenant,
            $workspace,
            $profile,
            $owner,
            $caregiver,
            'demo_caregiver_vitals_medications_tasks',
            self::CAREGIVER_SCOPES,
            self::CAREGIVER_ACTIONS,
        );

        $this->upsertGrant(
            $tenant,
            $workspace,
            $profile,
            $owner,
            $viewer,
            'demo_viewer_profile_basic',
            self::VIEWER_SCOPES,
            self::VIEWER_ACTIONS,
        );
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $actions
     */
    private function upsertGrant(
        Tenant $tenant,
        Workspace $workspace,
        HealthProfile $profile,
        User $grantor,
        User $grantee,
        string $purpose,
        array $scopes,
        array $actions,
    ): void {
        AccessGrant::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'profile_id' => $profile->id,
                'grantee_user_id' => $grantee->id,
                'purpose' => $purpose,
            ],
            [
                'tenant_id' => $tenant->id,
                'granted_by' => $grantor->id,
                'resource_scope' => $scopes,
                'allowed_actions' => $actions,
                'valid_from' => now()->subDays(2),
                'expires_at' => now()->addYear(),
                'status' => 'active',
                'revoked_at' => null,
                'revoked_by' => null,
                'revocation_reason' => null,
                'constraints' => [],
            ],
        );
    }

    private function seedConsents(
        Tenant $tenant,
        Workspace $workspace,
        HealthProfile $profile,
        Person $elder,
    ): void {
        $this->ensureConsent(
            $tenant,
            $workspace,
            $profile,
            $elder,
            'health_processing',
            'care_coordination',
            ['profile.basic', 'vitals', 'medications', 'tasks'],
            null,
        );

        $this->ensureConsent(
            $tenant,
            $workspace,
            $profile,
            $elder,
            'data_export',
            'data_export',
            ['profile.basic', 'vitals', 'medications', 'tasks', 'appointments', 'documents'],
            null,
        );

        // AI is off in the demo. A withdrawn row makes that visible in the
        // consent ledger instead of relying on an absent row.
        $this->ensureConsent(
            $tenant,
            $workspace,
            $profile,
            $elder,
            'ai_processing',
            'ai_processing',
            [],
            now(),
        );
    }

    /**
     * @param  list<string>  $scope
     */
    private function ensureConsent(
        Tenant $tenant,
        Workspace $workspace,
        HealthProfile $profile,
        Person $elder,
        string $type,
        string $purpose,
        array $scope,
        ?Carbon $withdrawnAt,
    ): void {
        $exists = ConsentRecord::query()
            ->where('workspace_id', $workspace->id)
            ->where('profile_id', $profile->id)
            ->where('consent_type', $type)
            ->where('purpose', $purpose)
            ->exists();

        if ($exists) {
            return;
        }

        ConsentRecord::query()->create([
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'profile_id' => $profile->id,
            'consent_type' => $type,
            'purpose' => $purpose,
            'scope' => $scope,
            'policy_version' => 'v1.0.0',
            'consenting_subject_id' => $elder->id,
            'legal_basis_type' => 'consent',
            'granted_at' => now()->subDays(180),
            'withdrawn_at' => $withdrawnAt,
            'evidence_reference' => 'demo:seed',
            'superseded_by_id' => null,
        ]);
    }

    /**
     * 180 readings across ~180 days: one blood pressure and one glucose
     * reading every other day. The values trend from high to near target.
     */
    private function seedMeasurements(HealthProfile $profile, User $owner): void
    {
        for ($i = 0; $i < 90; $i++) {
            $daysAgo = 179 - ($i * 2);
            $progress = $i / 89;

            $systolic = (int) round(158 - (30 * $progress) + (($i % 5) - 2));
            $diastolic = (int) round(96 - (18 * $progress) + (($i % 4) - 2));

            $this->measurements->create($profile, $owner, [
                'type' => 'blood_pressure',
                'value' => $systolic,
                'secondary_value' => $diastolic,
                'recorded_at' => $this->demoTime($daysAgo, 8),
                'source' => 'manual',
            ]);
        }

        for ($i = 0; $i < 90; $i++) {
            $daysAgo = 180 - ($i * 2);
            $progress = $i / 89;

            $glucose = (int) round(168 - (50 * $progress) + (($i % 7) - 3));

            $this->measurements->create($profile, $owner, [
                'type' => 'blood_glucose',
                'value' => $glucose,
                'context' => $i % 3 === 0 ? 'fasting' : 'random',
                'recorded_at' => $this->demoTime($daysAgo, 7),
                'source' => 'manual',
            ]);
        }
    }

    private function seedConditions(HealthProfile $profile, User $owner): void
    {
        $this->conditions->create($profile, $owner, [
            'code' => 'I10',
            'name' => 'ความดันโลหิตสูง',
            'diagnosed_at' => '2015-03-01',
            'status' => 'active',
        ]);

        $this->conditions->create($profile, $owner, [
            'code' => 'E11',
            'name' => 'เบาหวานชนิดที่ 2',
            'diagnosed_at' => '2018-07-15',
            'status' => 'active',
        ]);
    }

    private function seedAllergy(HealthProfile $profile, User $owner): void
    {
        $this->allergies->create($profile, $owner, [
            'substance' => 'Penicillin',
            'reaction' => 'ผื่นลมพิษ',
            'severity' => 'severe',
            'noted_at' => '2010-01-15',
        ]);
    }

    private function seedMedications(HealthProfile $profile, User $owner): void
    {
        $planSpecs = [
            [
                'name' => 'Amlodipine',
                'dosage' => '5 mg',
                'doses' => 1,
                'times' => ['08:00'],
                'starts' => 180,
            ],
            [
                'name' => 'Metformin',
                'dosage' => '500 mg',
                'doses' => 2,
                'times' => ['08:00', '20:00'],
                'starts' => 180,
            ],
            [
                'name' => 'Atorvastatin',
                'dosage' => '20 mg',
                'doses' => 1,
                'times' => ['20:00'],
                'starts' => 120,
            ],
        ];

        $plans = [];

        foreach ($planSpecs as $spec) {
            $plans[] = $this->plans->create($profile, $owner, [
                'name' => $spec['name'],
                'dosage' => $spec['dosage'],
                'form' => 'tablet',
                'route' => 'oral',
                'doses_per_day' => $spec['doses'],
                'schedule_times' => $spec['times'],
                'starts_on' => now()->subDays($spec['starts'])->toDateString(),
                'prescribed_by' => 'นพ. ทดสอบ',
                'instructions' => 'รับประทานตามแพทย์สั่ง',
                'source' => 'manual',
            ]);
        }

        // 200 reported doses: 70 + 80 + 50. Adherence improves over time, which
        // is the visible story the medication adherence screen should show.
        $recordSpecs = [
            ['plan' => 0, 'count' => 70, 'hour' => 8, 'alternate' => false],
            ['plan' => 1, 'count' => 80, 'hour' => 8, 'alternate' => true],
            ['plan' => 2, 'count' => 50, 'hour' => 20, 'alternate' => false],
        ];

        foreach ($recordSpecs as $spec) {
            for ($i = 0; $i < $spec['count']; $i++) {
                $daysAgo = 179 - (int) floor(178 * $i / max(1, $spec['count'] - 1));
                $hour = $spec['alternate'] && $i % 2 === 1 ? 20 : $spec['hour'];

                $scheduledFor = $this->demoTime($daysAgo, $hour);
                $status = $this->doseStatus($i, $spec['count']);

                $this->medicationRecords->create($profile, $owner, [
                    'plan_id' => $plans[$spec['plan']]->id,
                    'status' => $status,
                    'scheduled_for' => $scheduledFor,
                    'recorded_at' => $scheduledFor->copy()->addMinutes(12),
                    'note' => $status === 'skipped' ? 'ลืมรับประทานยา' : null,
                    'source' => 'manual',
                ]);
            }
        }
    }

    /**
     * Early records miss more doses than late records. `skipped` always carries
     * a note because the domain rules require one.
     */
    private function doseStatus(int $index, int $count): string
    {
        $early = $index < (int) ceil($count / 3);

        if ($early && $index % 6 === 0) {
            return 'missed';
        }

        if ($early && $index % 11 === 0) {
            return 'skipped';
        }

        if (! $early && $index % 23 === 0) {
            return 'missed';
        }

        if (! $early && $index % 17 === 0) {
            return 'skipped';
        }

        return 'taken';
    }

    /**
     * 12 appointments: 2 upcoming and 10 in the past. Past rows are written
     * directly through the model because the domain service deliberately
     * refuses to schedule a new visit in the past; no state-machine side effect
     * is needed for a historical record that is already closed.
     */
    private function seedAppointments(
        Tenant $tenant,
        Workspace $workspace,
        HealthProfile $profile,
        User $owner,
    ): void {
        $specs = [
            ['days' => 3, 'status' => Appointment::STATUS_PLANNED, 'title' => 'นัดตรวจความดันโลหิต'],
            ['days' => 10, 'status' => Appointment::STATUS_CONFIRMED, 'title' => 'นัดพบแพทย์ระบบต่อมไร้ท่อ'],
            ['days' => -7, 'status' => Appointment::STATUS_ATTENDED, 'title' => 'ตรวจติดตามผลน้ำตาล'],
            ['days' => -21, 'status' => Appointment::STATUS_ATTENDED, 'title' => 'ตรวจเท้าผู้ป่วยเบาหวาน'],
            ['days' => -35, 'status' => Appointment::STATUS_CANCELLED, 'title' => 'นัดตรวจสายตา'],
            ['days' => -49, 'status' => Appointment::STATUS_ATTENDED, 'title' => 'ตรวจสุขภาพประจำปี'],
            ['days' => -63, 'status' => Appointment::STATUS_MISSED, 'title' => 'นัดรับยาต่อเนื่อง'],
            ['days' => -77, 'status' => Appointment::STATUS_ATTENDED, 'title' => 'ตรวจติดตามผลไต'],
            ['days' => -91, 'status' => Appointment::STATUS_ATTENDED, 'title' => 'พบนักโภชนาการ'],
            ['days' => -105, 'status' => Appointment::STATUS_CANCELLED, 'title' => 'นัดกายภาพบำบัด'],
            ['days' => -120, 'status' => Appointment::STATUS_MISSED, 'title' => 'นัดฉีดวัคซีนไข้หวัดใหญ่'],
            ['days' => -150, 'status' => Appointment::STATUS_ATTENDED, 'title' => 'ตรวจคัดกรองเบาหวาน'],
        ];

        foreach ($specs as $spec) {
            $scheduledAt = now()->addDays($spec['days'])->setTime(10, 0);
            $createdAt = $scheduledAt->copy()->subDays(14);

            $appointment = new Appointment([
                'tenant_id' => $tenant->id,
                'workspace_id' => $workspace->id,
                'profile_id' => $profile->id,
                'created_by' => $owner->id,
                'source' => 'manual',
                'title' => $spec['title'],
                'provider_name' => 'นพ. ทดสอบ',
                'facility' => 'โรงพยาบาลทดสอบ',
                'purpose' => 'ตรวจติดตามอาการ',
                'location' => 'ห้องตรวจผู้สูงอายุ',
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => 30,
                'status' => $spec['status'],
                'cancelled_reason' => $spec['status'] === Appointment::STATUS_CANCELLED
                    ? 'เลื่อนนัด'
                    : null,
                'rescheduled_from_id' => null,
                'transported_by_task_id' => null,
                'prepared_documents' => [],
                'follow_up_task_id' => null,
                'version' => 1,
            ]);

            $appointment->created_at = $createdAt;
            $appointment->updated_at = $createdAt;
            $appointment->save();
        }
    }

    /**
     * 25 care tasks in mixed states. Every state is reached through the real
     * transition service, so `task_events`, `audit_events` and the timeline are
     * genuine history rather than fabricated rows.
     */
    private function seedCareTasks(
        Tenant $tenant,
        Workspace $workspace,
        HealthProfile $profile,
        User $owner,
        User $caregiver,
    ): void {
        $specs = [
            // routine (7)
            ['key' => 'routine-scheduled-1', 'tier' => 'routine', 'type' => 'MEDICATION_SUPPORT', 'title' => 'เตือนรับประทานยาเช้า', 'days' => -1, 'target' => 'scheduled', 'priority' => 'high'],
            ['key' => 'routine-scheduled-2', 'tier' => 'routine', 'type' => 'VITAL_MEASUREMENT', 'title' => 'วัดความดันโลหิตเช้า', 'days' => 0, 'target' => 'scheduled'],
            ['key' => 'routine-scheduled-3', 'tier' => 'routine', 'type' => 'DAILY_CARE', 'title' => 'พาเดินออกกำลังกาย', 'days' => 2, 'target' => 'scheduled', 'priority' => 'low'],
            ['key' => 'routine-completed-1', 'tier' => 'routine', 'type' => 'DAILY_CARE', 'title' => 'เตรียมอาหารเช้า', 'days' => -20, 'target' => 'completed'],
            ['key' => 'routine-completed-2', 'tier' => 'routine', 'type' => 'VITAL_MEASUREMENT', 'title' => 'ชั่งน้ำหนักประจำสัปดาห์', 'days' => -15, 'target' => 'completed'],
            ['key' => 'routine-completed-3', 'tier' => 'routine', 'type' => 'MEDICATION_SUPPORT', 'title' => 'จัดยาใส่กล่องรายสัปดาห์', 'days' => -10, 'target' => 'completed'],
            ['key' => 'routine-cancelled-1', 'tier' => 'routine', 'type' => 'DAILY_CARE', 'title' => 'ทำความสะอาดห้องนอน', 'days' => 5, 'target' => 'cancelled'],

            // delegated (15)
            ['key' => 'delegated-open-1', 'tier' => 'delegated', 'type' => 'APPOINTMENT', 'title' => 'จองรถรับส่งไปโรงพยาบาล', 'days' => -2, 'target' => 'open', 'priority' => 'high'],
            ['key' => 'delegated-open-2', 'tier' => 'delegated', 'type' => 'DOCUMENT_FOLLOW_UP', 'title' => 'ขอใบสรุปผลการรักษา', 'days' => 0, 'target' => 'open'],
            ['key' => 'delegated-open-3', 'tier' => 'delegated', 'type' => 'FOLLOW_UP', 'title' => 'โทรสอบถามผลตรวจเลือด', 'days' => 2, 'target' => 'open'],
            ['key' => 'delegated-assigned-1', 'tier' => 'delegated', 'type' => 'MEDICATION_SUPPORT', 'title' => 'รับยาที่ร้านขายยา', 'days' => 0, 'target' => 'assigned'],
            ['key' => 'delegated-assigned-2', 'tier' => 'delegated', 'type' => 'DAILY_CARE', 'title' => 'ซื้ออาหารเสริมตามแผน', 'days' => 1, 'target' => 'assigned'],
            ['key' => 'delegated-assigned-3', 'tier' => 'delegated', 'type' => 'APPOINTMENT', 'title' => 'ยืนยันนัดกับคลินิก', 'days' => 3, 'target' => 'assigned'],
            ['key' => 'delegated-accepted-1', 'tier' => 'delegated', 'type' => 'VITAL_MEASUREMENT', 'title' => 'วัดความดันรอบเย็น', 'days' => 1, 'target' => 'accepted'],
            ['key' => 'delegated-accepted-2', 'tier' => 'delegated', 'type' => 'FOLLOW_UP', 'title' => 'ติดตามผลหลังพบแพทย์', 'days' => 2, 'target' => 'accepted'],
            ['key' => 'delegated-progress-1', 'tier' => 'delegated', 'type' => 'DAILY_CARE', 'title' => 'พาไปตัดผม', 'days' => 0, 'target' => 'in_progress'],
            ['key' => 'delegated-progress-2', 'tier' => 'delegated', 'type' => 'DOCUMENT_FOLLOW_UP', 'title' => 'ถ่ายสำเนาบัตรรักษาพยาบาล', 'days' => 1, 'target' => 'in_progress'],
            ['key' => 'delegated-blocked-1', 'tier' => 'delegated', 'type' => 'APPOINTMENT', 'title' => 'นัดตรวจพิเศษเพิ่มเติม', 'days' => -1, 'target' => 'blocked', 'priority' => 'high'],
            ['key' => 'delegated-blocked-2', 'tier' => 'delegated', 'type' => 'MEDICATION_SUPPORT', 'title' => 'ขอใบสั่งยาฉบับใหม่', 'days' => 0, 'target' => 'blocked'],
            ['key' => 'delegated-completed-1', 'tier' => 'delegated', 'type' => 'FOLLOW_UP', 'title' => 'ส่งผลตรวจให้แพทย์ทางไลน์', 'days' => -12, 'target' => 'completed'],
            ['key' => 'delegated-completed-2', 'tier' => 'delegated', 'type' => 'DAILY_CARE', 'title' => 'เปลี่ยนผ้าปูที่นอน', 'days' => -8, 'target' => 'completed'],
            ['key' => 'delegated-cancelled-1', 'tier' => 'delegated', 'type' => 'APPOINTMENT', 'title' => 'นัดปรึกษานักกายภาพบำบัด', 'days' => 4, 'target' => 'cancelled'],

            // clinical (3)
            ['key' => 'clinical-assigned-1', 'tier' => 'clinical', 'type' => 'VITAL_MEASUREMENT', 'title' => 'ตรวจระดับน้ำตาลปลายนิ้ว', 'days' => 2, 'target' => 'assigned', 'priority' => 'high'],
            ['key' => 'clinical-progress-1', 'tier' => 'clinical', 'type' => 'FOLLOW_UP', 'title' => 'ประเมินแผลที่เท้า', 'days' => 0, 'target' => 'in_progress', 'priority' => 'high'],
            ['key' => 'clinical-verified-1', 'tier' => 'clinical', 'type' => 'VITAL_MEASUREMENT', 'title' => 'ตรวจความดันและชีพจร', 'days' => -6, 'target' => 'verified'],
        ];

        foreach ($specs as $spec) {
            $assignee = $spec['tier'] !== 'routine'
                && in_array($spec['target'], ['assigned', 'accepted', 'in_progress', 'blocked', 'completed', 'verified'], true)
                    ? $caregiver
                    : null;

            $dueAt = now()->addDays($spec['days'])->setTime(9, 0);

            [$task] = $this->tasks->create(
                $workspace,
                $owner,
                [
                    'profile_id' => $profile->id,
                    'tier' => $spec['tier'],
                    'type' => $spec['type'],
                    'title' => $spec['title'],
                    'description' => 'งานตัวอย่างสำหรับเดโม (ข้อมูลสมมติ)',
                    'priority' => $spec['priority'] ?? 'normal',
                    'scheduled_for' => $dueAt->copy()->subHours(2),
                    'due_at' => $dueAt,
                    'timezone' => 'Asia/Bangkok',
                    'evidence_policy' => CareTask::EVIDENCE_NONE,
                    'assignee_user_id' => $assignee?->id,
                    'source' => 'manual',
                ],
                'demo-task-'.$spec['key'],
                false,
                $assignee !== null ? CareTask::STATUS_ASSIGNED : null,
            );

            try {
                $this->advanceDemoTask($workspace, $task->refresh(), $spec, $owner, $caregiver);
            } catch (Throwable $exception) {
                throw new \RuntimeException(
                    sprintf(
                        'Could not seed demo task [%s] (tier=%s, target=%s): %s',
                        $spec['key'],
                        $spec['tier'],
                        $spec['target'],
                        $exception->getMessage(),
                    ),
                    0,
                    $exception,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function advanceDemoTask(
        Workspace $workspace,
        CareTask $task,
        array $spec,
        User $owner,
        User $caregiver,
    ): void {
        $target = (string) $spec['target'];

        if (in_array($target, ['scheduled', 'open', 'assigned'], true)) {
            return;
        }

        if ($spec['tier'] === 'routine') {
            if ($target === 'completed') {
                $this->transitions->apply($workspace, $task->id, $owner, 'complete', $task->version, [
                    'outcome' => CareTask::OUTCOME_DONE,
                ]);
            } elseif ($target === 'cancelled') {
                $this->transitions->apply($workspace, $task->id, $owner, 'cancel', $task->version, [
                    'reason' => 'แผนการดูแลเปลี่ยน',
                ]);
            }

            return;
        }

        if ($target === 'cancelled') {
            $this->transitions->apply($workspace, $task->id, $owner, 'cancel', $task->version, [
                'reason' => 'แผนการดูแลเปลี่ยน',
            ]);

            return;
        }

        if ($spec['tier'] === 'delegated') {
            if (in_array($target, ['accepted', 'in_progress', 'blocked', 'completed'], true)) {
                $task = $this->transitions->apply(
                    $workspace,
                    $task->id,
                    $caregiver,
                    'accept',
                    $task->version,
                );
            }

            if (in_array($target, ['in_progress', 'blocked', 'completed'], true)) {
                $task = $this->transitions->apply(
                    $workspace,
                    $task->id,
                    $caregiver,
                    'start',
                    $task->version,
                );
            }

            if ($target === 'blocked') {
                $this->transitions->apply($workspace, $task->id, $caregiver, 'block', $task->version, [
                    'blocked_reason' => 'รอผลตรวจจากโรงพยาบาล',
                    'review_at' => now()->addDays(2)->toIso8601String(),
                ]);

                return;
            }

            if ($target === 'completed') {
                $this->transitions->apply($workspace, $task->id, $caregiver, 'complete', $task->version, [
                    'outcome' => CareTask::OUTCOME_DONE,
                ]);
            }

            return;
        }

        // clinical: assigned -> in_progress -> completed(pending) -> verified.
        if (in_array($target, ['in_progress', 'verified'], true)) {
            $task = $this->transitions->apply(
                $workspace,
                $task->id,
                $caregiver,
                'start',
                $task->version,
            );
        }

        if ($target === 'verified') {
            $task = $this->transitions->apply(
                $workspace,
                $task->id,
                $caregiver,
                'complete',
                $task->version,
                ['outcome' => CareTask::OUTCOME_DONE],
            );

            // Separation of duties (CT-003): a different actor verifies.
            $this->transitions->apply($workspace, $task->id, $owner, 'verify', $task->version);
        }
    }

    /**
     * Three metadata-only documents. No object is written to storage: the demo
     * must never leave a real file (or a PHI-shaped filename) behind.
     */
    private function seedDocuments(
        Tenant $tenant,
        Workspace $workspace,
        HealthProfile $profile,
        User $owner,
    ): void {
        $specs = [
            ['kind' => 'lab_result', 'name' => 'demo-lab-result.pdf', 'days' => 30],
            ['kind' => 'prescription', 'name' => 'demo-prescription.pdf', 'days' => 60],
            ['kind' => 'discharge_summary', 'name' => 'demo-discharge-summary.pdf', 'days' => 90],
        ];

        foreach ($specs as $index => $spec) {
            $id = Uuid7::generate();

            $document = new MedicalDocument([
                'tenant_id' => $tenant->id,
                'workspace_id' => $workspace->id,
                'profile_id' => $profile->id,
                'uploaded_by' => $owner->id,
                'kind' => $spec['kind'],
                'mime_type' => 'application/pdf',
                'size_bytes' => 120_000 + ($index * 4_096),
                'sha256' => hash('sha256', 'demo-document-'.$index),
                'storage_disk' => 'local',
                'storage_path' => 'documents/'.$tenant->id.'/'.$id,
                'original_name' => $spec['name'],
                'captured_at' => now()->subDays($spec['days']),
                'scan_status' => MedicalDocument::SCAN_SKIPPED,
                'status' => MedicalDocument::STATUS_AVAILABLE,
                'version' => 1,
            ]);

            $document->id = $id;
            $document->save();
        }
    }

    private function demoTime(int $daysAgo, int $hour): Carbon
    {
        return now()->subDays($daysAgo)->setTime($hour, 0, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function counts(Tenant $tenant, Workspace $workspace): array
    {
        return TenantContext::run($tenant->id, $workspace->id, function () use ($workspace): array {
            $workspaceId = $workspace->id;
            $now = now();

            return [
                'members' => DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->count(),
                'persons' => DB::table('persons')->where('workspace_id', $workspaceId)->count(),
                'profiles' => DB::table('health_profiles')->where('workspace_id', $workspaceId)->count(),
                'profile_id' => DB::table('health_profiles')
                    ->where('workspace_id', $workspaceId)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->value('id'),
                'measurements' => DB::table('health_measurements')->where('workspace_id', $workspaceId)->count(),
                'blood_pressure' => DB::table('health_measurements')
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'blood_pressure')
                    ->count(),
                'blood_glucose' => DB::table('health_measurements')
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'blood_glucose')
                    ->count(),
                'conditions' => DB::table('health_conditions')->where('workspace_id', $workspaceId)->count(),
                'allergies' => DB::table('allergies')->where('workspace_id', $workspaceId)->count(),
                'medication_plans' => DB::table('medication_plans')->where('workspace_id', $workspaceId)->count(),
                'medication_records' => DB::table('medication_records')->where('workspace_id', $workspaceId)->count(),
                'appointments' => DB::table('appointments')->where('workspace_id', $workspaceId)->count(),
                'appointments_upcoming' => DB::table('appointments')
                    ->where('workspace_id', $workspaceId)
                    ->where('scheduled_at', '>=', $now)
                    ->whereIn('status', [Appointment::STATUS_PLANNED, Appointment::STATUS_CONFIRMED])
                    ->count(),
                'care_tasks' => DB::table('care_tasks')->where('workspace_id', $workspaceId)->count(),
                'task_events' => DB::table('task_events')->where('workspace_id', $workspaceId)->count(),
                'documents' => DB::table('medical_documents')->where('workspace_id', $workspaceId)->count(),
                'grants' => DB::table('access_grants')->where('workspace_id', $workspaceId)->count(),
                'consents' => DB::table('consent_records')->where('workspace_id', $workspaceId)->count(),
                'timeline_entries' => DB::table('timeline_entries')->where('workspace_id', $workspaceId)->count(),
            ];
        });
    }

    /**
     * @param  array{workspace: Workspace, state: string, deleted: array<string, int>, counts: array<string, int>}  $report
     */
    private function renderReport(Tenant $tenant, array $report): void
    {
        $workspace = $report['workspace'];
        $counts = $report['counts'];

        $this->info('LuckyCare+ demo data');
        $this->line(sprintf('Environment: %s', $this->laravel->environment()));
        $this->newLine();

        $this->table(['Item', 'Value'], [
            ['State', $report['state']],
            ['Tenant', $tenant->id],
            ['Tenant slug', $tenant->slug],
            ['Workspace', $workspace->id],
            ['Workspace slug', $workspace->slug],
            ['Workspace name', $workspace->name],
        ]);

        $this->table(['Data', 'Count'], [
            ['Members (owner / caregiver / viewer)', $counts['members']],
            ['Persons', $counts['persons']],
            ['Health profiles', $counts['profiles']],
            ['Measurements (blood pressure / glucose)', sprintf('%d (%d / %d)', $counts['measurements'], $counts['blood_pressure'], $counts['blood_glucose'])],
            ['Conditions', $counts['conditions']],
            ['Allergies', $counts['allergies']],
            ['Medication plans', $counts['medication_plans']],
            ['Medication dose records', $counts['medication_records']],
            ['Appointments (upcoming)', sprintf('%d (%d)', $counts['appointments'], $counts['appointments_upcoming'])],
            ['Care tasks', $counts['care_tasks']],
            ['Task events', $counts['task_events']],
            ['Medical documents (metadata only)', $counts['documents']],
            ['Access grants', $counts['grants']],
            ['Consent records', $counts['consents']],
            ['Timeline entries', $counts['timeline_entries']],
        ]);

        if ($report['state'] === 'reset') {
            $removed = array_filter($report['deleted'], static fn (int $rows): bool => $rows > 0);
            arsort($removed);
            $rows = [];

            foreach ($removed as $table => $count) {
                $rows[] = [$table, $count];
            }

            if ($rows !== []) {
                $this->table(['Removed before reseed (demo workspace only)', 'Rows'], $rows);
            }
        }

        $this->line('Login steps (synthetic accounts, password login):');
        $this->line('  owner      '.self::OWNER_EMAIL.' / '.self::PASSWORD);
        $this->line('  caregiver  '.self::CAREGIVER_EMAIL.' / '.self::PASSWORD);
        $this->line('  viewer     '.self::VIEWER_EMAIL.' / '.self::PASSWORD);
        $this->newLine();
        $this->line('  curl -sS -X POST http://localhost:8080/api/v1/auth/login \\');
        $this->line("    -H 'Content-Type: application/json' \\");
        $this->line('    -d \'{"email":"'.self::OWNER_EMAIL.'","password":"'.self::PASSWORD.'"}\'');
        $this->newLine();
        $this->line('  Workspace: '.$workspace->id);

        if (is_string($counts['profile_id'] ?? null)) {
            $this->line('  Profile:   '.$counts['profile_id']);
        }

        if (config('otp.demo_mode', false) === true) {
            $this->line('  OTP demo mode is ON in this CLI environment: request a code with the bearer token.');
        } else {
            $this->line('  OTP_DEMO_MODE=false in this CLI environment; use the password above.');
            $this->line('  The API container may be configured separately; the smoke check reports the path it used.');
        }

        $this->newLine();
        $this->warn('Synthetic data only. Never load this workspace into a deployment that holds real records.');
        $this->line('Reset (asks for confirmation): php artisan demo:seed --reset');
        $this->line('Automation reset:              php artisan demo:seed --reset --force');
        $this->line('Post-deploy check:             bash backend/scripts/smoke.sh --base-url=http://localhost:8080');
    }
}
