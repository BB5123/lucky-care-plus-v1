<?php

namespace Tests\Feature\Timeline;

use App\Models\User;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\HealthRecord\Models\HealthCondition;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\People\Models\Person;
use App\Modules\Timeline\Application\TimelineProjector;
use App\Modules\Timeline\Domain\TimelinePhiViolationException;
use App\Modules\Timeline\Models\TimelineEntry;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * P1.7 acceptance suite — timeline projection (CRM-011..014).
 *
 * The tests go through the real write paths (model observers) and the real
 * HTTP stack, so the projection wiring, per-entry authorization, redaction and
 * the rebuild command are all exercised as they run in production.
 */
class TimelineTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    private User $owner;

    private User $caregiver;

    private User $viewer;

    private User $outsider;

    private HealthProfile $profile;

    private HealthProfile $otherWorkspaceProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('tl-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'TL Workspace');
        $this->otherWorkspace = $this->createWorkspace($this->tenant, 'TL Other Workspace');

        $this->owner = User::factory()->create();
        $this->caregiver = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->addMember($this->workspace, $this->owner, 'profile_owner');
        $this->addMember($this->workspace, $this->caregiver, 'caregiver');
        $this->addMember($this->workspace, $this->viewer, 'viewer');
        $this->addMember($this->otherWorkspace, $this->owner, 'profile_owner');

        $this->useWorkspace($this->workspace);

        $ownerPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'display_name' => 'Owner Person',
        ]);

        $this->profile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $ownerPerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->otherWorkspace);

        $otherPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'user_id' => null,
            'display_name' => 'Other Person',
        ]);

        $this->otherWorkspaceProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'person_id' => $otherPerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->workspace);
    }

    // -- TL-T01 -------------------------------------------------------------

    public function test_tl_t01_source_observers_project_only_safe_entries(): void
    {
        $measurement = $this->measurement($this->profile, [
            'type' => 'blood_pressure',
            'value' => 158,
            'secondary_value' => 96,
            'note' => 'PHI note that must never leave the source table',
            'recorded_at' => now()->subMinutes(5),
        ]);

        $condition = $this->condition($this->profile);
        $plan = $this->medicationPlan($this->profile);
        $plan->forceFill([
            'status' => 'discontinued',
            'discontinued_at' => now(),
        ])->save();
        $task = $this->careTask($this->profile);
        $event = $this->taskEvent($task, 'completed', 'in_progress', 'completed');
        $appointment = $this->appointment($this->profile);
        $appointment->forceFill(['status' => Appointment::STATUS_ATTENDED])->save();

        $measurementEntry = $this->entryFor('health_measurement', $measurement->id);

        $this->assertNotNull($measurementEntry, 'The measurement observer must project one entry');
        $this->assertSame('measurement', $measurementEntry->type);
        $this->assertSame('timeline.measurement.recorded', $measurementEntry->summary_key);
        $this->assertSame(['type' => 'blood_pressure'], $measurementEntry->metadata);

        $entries = TimelineEntry::query()->get();

        // measurement, condition, plan created, plan discontinued, task event,
        // appointment created, appointment attended.
        $this->assertCount(7, $entries);
        $this->assertNotNull($this->entryFor('health_condition', $condition->id));
        $this->assertNotNull($this->entryFor('medication_plan', $plan->id));
        $this->assertNotNull($this->entryFor('medication_plan_discontinued', $plan->id));
        $this->assertNotNull($this->entryFor('task_event', $event->id));
        $this->assertNotNull($this->entryFor('appointment', $appointment->id));
        $this->assertNotNull($this->entryFor('appointment_attended', $appointment->id));

        $serialised = (string) json_encode($entries->map->only(['summary_key', 'metadata'])->all());

        // Source free text and measured values never enter the read model.
        $this->assertStringNotContainsString('PHI note', $serialised);
        $this->assertStringNotContainsString('must not be projected', $serialised);
        $this->assertStringNotContainsString('158', $serialised);

        foreach ($entries as $entry) {
            $this->assertSame([], array_values(array_intersect(
                array_keys($entry->metadata),
                ['value', 'secondary_value', 'note', 'notes', 'description', 'name', 'substance', 'reaction', 'title', 'purpose', 'location', 'instructions'],
            )));
        }
    }

    // -- TL-T02 -------------------------------------------------------------

    public function test_tl_t02_reprojection_is_idempotent_on_the_source_key(): void
    {
        $refId = Uuid7::generate();
        $projector = app(TimelineProjector::class)->forSubject(
            (string) $this->tenant->id,
            (string) $this->workspace->id,
            (string) $this->profile->id,
        );

        $projector->record(
            'measurement',
            'health_measurement',
            $refId,
            '2026-10-20T10:00:00+00:00',
            'timeline.measurement.recorded',
            ['type' => 'heart_rate'],
        );

        $projector->record(
            'measurement',
            'health_measurement',
            $refId,
            '2026-10-21T10:00:00+00:00',
            'timeline.measurement.recorded',
            ['type' => 'heart_rate'],
        );

        $entries = TimelineEntry::query()
            ->where('ref_type', 'health_measurement')
            ->where('ref_id', $refId)
            ->get();

        $this->assertCount(1, $entries, 'A retry or replay must not duplicate the entry');
        $this->assertSame(
            Carbon::parse('2026-10-21T10:00:00+00:00')->timestamp,
            $entries->first()->occurred_at->timestamp,
        );
    }

    // -- TL-T03 -------------------------------------------------------------

    public function test_tl_t03_phi_shaped_metadata_is_rejected(): void
    {
        $projector = app(TimelineProjector::class)->forSubject(
            (string) $this->tenant->id,
            (string) $this->workspace->id,
            (string) $this->profile->id,
        );

        try {
            $projector->record(
                'measurement',
                'health_measurement',
                Uuid7::generate(),
                now()->toIso8601String(),
                'timeline.measurement.recorded',
                ['value' => 158],
            );

            $this->fail('PHI metadata must not be projected');
        } catch (TimelinePhiViolationException) {
            // Expected: the projector is loud, never silently redacting.
        }

        try {
            $projector->record(
                'measurement',
                'health_measurement',
                Uuid7::generate(),
                now()->toIso8601String(),
                'timeline.measurement.recorded',
                ['plan' => ['medication_name' => 'Secret']],
            );

            $this->fail('Nested PHI metadata must not be projected');
        } catch (TimelinePhiViolationException) {
            // Expected.
        }

        $this->assertSame(0, TimelineEntry::query()->count());
    }

    // -- TL-T04 -------------------------------------------------------------

    public function test_tl_t04_page_orders_filters_and_paginates(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));

        $projector = app(TimelineProjector::class)->forSubject(
            (string) $this->tenant->id,
            (string) $this->workspace->id,
            (string) $this->profile->id,
        );

        $projector->record('measurement', 'health_measurement', Uuid7::generate(), '2026-10-20T10:00:00+00:00', 'timeline.measurement.recorded', ['type' => 'heart_rate']);
        $projector->record('condition', 'health_condition', Uuid7::generate(), '2026-10-20T11:00:00+00:00', 'timeline.condition.recorded');
        $projector->record('allergy', 'allergy', Uuid7::generate(), '2026-10-20T12:00:00+00:00', 'timeline.allergy.recorded');

        Sanctum::actingAs($this->owner);

        $page = $this->getJson($this->timelinePath().'?limit=2')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'allergy')
            ->assertJsonPath('data.1.type', 'condition')
            ->assertJsonPath('meta.limit', 2);

        $cursor = $page->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $this->assertNotSame('', $cursor);

        $this->getJson($this->timelinePath().'?limit=2&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'measurement');

        $this->getJson($this->timelinePath().'?types=measurement')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'measurement');

        $this->getJson($this->timelinePath().'?from=2026-10-20&to=2026-10-20&types=condition')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'condition');

        $this->getJson($this->timelinePath().'?types=lab_results')
            ->assertStatus(422);

        $this->getJson($this->timelinePath().'?profile_id='.$this->otherWorkspaceProfile->id)
            ->assertStatus(422);
    }

    // -- TL-T05 -------------------------------------------------------------

    public function test_tl_t05_entries_without_the_owning_scope_are_redacted(): void
    {
        $this->measurement($this->profile, ['type' => 'heart_rate']);
        $this->condition($this->profile);
        $this->appointment($this->profile);

        $this->grant($this->caregiver, ['timeline', 'profile.basic']);

        Sanctum::actingAs($this->caregiver);

        $response = $this->getJson($this->timelinePath())->assertOk();

        $entries = collect($response->json('data'))->keyBy('type');

        $this->assertFalse($entries['condition']['redacted'], 'profile.basic covers conditions');
        $this->assertTrue($entries['appointment']['redacted'], 'an appointment scope was not granted');
        $this->assertTrue($entries['measurement']['redacted'], 'a timeline grant alone must not leak vitals');
        $this->assertSame(
            ['occurred_at', 'redacted', 'type'],
            $this->sortedKeys($entries['measurement']),
            'A redacted entry may expose only its type, time and redaction flag',
        );

        // No timeline grant at all: the surface itself is denied.
        Sanctum::actingAs($this->viewer);

        $this->getJson($this->timelinePath())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    // -- TL-T06 -------------------------------------------------------------

    public function test_tl_t06_timeline_never_crosses_the_workspace_boundary(): void
    {
        $this->measurement($this->profile, ['type' => 'heart_rate']);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/workspaces/'.$this->otherWorkspace->id
            .'/profiles/'.$this->profile->id.'/timeline')
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');

        Sanctum::actingAs($this->outsider);

        $this->getJson($this->timelinePath())
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    // -- TL-T07 -------------------------------------------------------------

    public function test_tl_t07_rebuild_is_idempotent_and_removes_vanished_sources(): void
    {
        $measurement = $this->measurement($this->profile, ['type' => 'heart_rate']);

        $this->assertSame(1, TimelineEntry::query()->count());

        // A wiped projection is reconstructed from the source tables alone.
        TimelineEntry::query()->delete();

        $this->artisan('timeline:rebuild', ['--dry-run' => true])
            ->expectsOutputToContain('Total: created=1 updated=0 removed=0')
            ->assertSuccessful();

        $this->assertSame(0, TimelineEntry::query()->count(), '--dry-run must not write');

        $this->artisan('timeline:rebuild')
            ->expectsOutputToContain('Total: created=1 updated=0 removed=0')
            ->assertSuccessful();

        $this->assertSame(1, TimelineEntry::query()->count());

        $this->artisan('timeline:rebuild')
            ->expectsOutputToContain('Total: created=0 updated=0 removed=0')
            ->assertSuccessful();

        $this->artisan('timeline:rebuild', ['--profile' => $this->profile->id])
            ->expectsOutputToContain('Total: created=0 updated=0 removed=0')
            ->assertSuccessful();

        // A soft-deleted source is no longer projected; the rebuild removes
        // the stale entry instead of leaving it visible.
        $measurement->delete();

        $this->artisan('timeline:rebuild')
            ->expectsOutputToContain('Total: created=0 updated=0 removed=1')
            ->assertSuccessful();

        $this->assertSame(0, TimelineEntry::query()->count());
    }

    // -- TL-T08 -------------------------------------------------------------

    public function test_tl_t08_projection_failure_never_breaks_the_source_write(): void
    {
        Log::spy();

        $this->app->instance(TimelineProjector::class, new class extends TimelineProjector
        {
            public function record(
                string $type,
                string $refType,
                string $refId,
                string $occurredAt,
                string $summaryKey,
                array $metadata = [],
            ): void {
                throw new RuntimeException('projection unavailable');
            }
        });

        $measurement = $this->measurement($this->profile, ['type' => 'heart_rate']);

        $this->assertDatabaseHas('health_measurements', ['id' => $measurement->id]);
        $this->assertSame(0, TimelineEntry::query()->count());

        Log::shouldHaveReceived('error')->once()->withArgs(
            static function (string $message, array $context) use ($measurement): bool {
                return $message === 'timeline projection failed'
                    && ($context['ref_id'] ?? null) === $measurement->id
                    && ($context['ref_type'] ?? null) === 'health_measurement'
                    && ! array_key_exists('value', $context);
            },
        );
    }

    // -- TL-T09 -------------------------------------------------------------

    public function test_tl_t09_revoking_the_owning_scope_redacts_without_a_cache_window(): void
    {
        $measurement = $this->measurement($this->profile, ['type' => 'heart_rate']);

        $this->grant($this->caregiver, ['timeline']);
        $vitals = $this->grant($this->caregiver, ['vitals']);

        Sanctum::actingAs($this->caregiver);

        $this->getJson($this->timelinePath())
            ->assertOk()
            ->assertJsonPath('data.0.type', 'measurement')
            ->assertJsonPath('data.0.redacted', false)
            ->assertJsonPath('data.0.ref.id', $measurement->id);

        $this->useWorkspace();
        $vitals->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        $this->getJson($this->timelinePath())
            ->assertOk()
            ->assertJsonPath('data.0.type', 'measurement')
            ->assertJsonPath('data.0.redacted', true)
            ->assertJsonMissingPath('data.0.ref')
            ->assertJsonMissingPath('data.0.summary_key');
    }

    // -- Fixtures -----------------------------------------------------------

    private function useWorkspace(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($this->tenant->id, $workspace->id);
    }

    private function timelinePath(?HealthProfile $profile = null, ?Workspace $workspace = null): string
    {
        $profile ??= $this->profile;
        $workspace ??= $this->workspace;

        return "/api/v1/workspaces/{$workspace->id}/profiles/{$profile->id}/timeline";
    }

    /**
     * @param  list<string>  $scopes
     */
    private function grant(User $grantee, array $scopes): AccessGrant
    {
        $this->useWorkspace();

        return AccessGrant::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'granted_by' => $this->owner->id,
            'grantee_user_id' => $grantee->id,
            'resource_scope' => $scopes,
            'allowed_actions' => ['read'],
            'purpose' => 'care_coordination',
            'valid_from' => now()->subMinute(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'constraints' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function measurement(HealthProfile $profile, array $attributes = []): HealthMeasurement
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return HealthMeasurement::forceCreate(array_merge([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'type' => 'heart_rate',
            'value' => 72,
            'unit' => 'bpm',
            'recorded_at' => now()->subHour(),
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'version' => 1,
        ], $attributes));
    }

    private function condition(HealthProfile $profile): HealthCondition
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return HealthCondition::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'name' => 'Condition name that must not be projected',
            'diagnosed_at' => now()->subWeek(),
            'status' => 'active',
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'version' => 1,
        ]);
    }

    private function medicationPlan(HealthProfile $profile): MedicationPlan
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return MedicationPlan::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'name' => 'Plan name that must not be projected',
            'dosage' => '5 mg',
            'form' => 'tablet',
            'doses_per_day' => 1,
            'schedule_times' => ['08:00'],
            'starts_on' => now()->subMonth()->toDateString(),
            'status' => 'active',
            'version' => 1,
        ]);
    }

    private function careTask(HealthProfile $profile): CareTask
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return CareTask::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'created_by' => $this->owner->id,
            'tier' => CareTask::TIER_DELEGATED,
            'type' => 'DAILY_CARE',
            'title' => 'Task title that must not be projected',
            'priority' => 'normal',
            'due_at' => now()->addHour(),
            'timezone' => 'Asia/Bangkok',
            'status' => CareTask::STATUS_IN_PROGRESS,
            'verification' => CareTask::VERIFICATION_NOT_REQUIRED,
            'evidence_policy' => CareTask::EVIDENCE_NONE,
            'escalation_level' => 0,
            'version' => 1,
            'source' => 'manual',
        ]);
    }

    private function taskEvent(CareTask $task, string $event, ?string $from, ?string $to): TaskEvent
    {
        TenantContext::set($task->tenant_id, $task->workspace_id);

        return TaskEvent::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $task->tenant_id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'actor_id' => $this->owner->id,
            'actor_type' => 'user',
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'reason' => null,
            'metadata' => [],
            'occurred_at' => now(),
        ]);
    }

    private function appointment(HealthProfile $profile): Appointment
    {
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return Appointment::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'created_by' => $this->owner->id,
            'source' => 'manual',
            'title' => 'Visit title that must not be projected',
            'scheduled_at' => now()->addDay(),
            'status' => Appointment::STATUS_PLANNED,
            'version' => 1,
        ]);
    }

    private function entryFor(string $refType, string $refId): ?TimelineEntry
    {
        return TimelineEntry::query()
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }
}
