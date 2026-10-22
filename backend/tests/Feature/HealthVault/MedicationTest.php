<?php

namespace Tests\Feature\HealthVault;

use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\HealthRecord\Models\MedicationRecord;
use App\Support\Database\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * P1.2 — medication plans, reported dose events, and 7-day adherence.
 *
 * A `taken` record is self-reported, not clinical proof of ingestion
 * (`.ai/05-CARE-COORDINATION.md` §3); the tests only assert what was reported.
 */
class MedicationTest extends HealthVaultTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function planPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Metformin',
            'dosage' => '500 mg',
            'form' => 'tablet',
            'route' => 'oral',
            'doses_per_day' => 2,
            'schedule_times' => ['08:00', '20:00'],
            'starts_on' => now()->subDays(3)->toDateString(),
            'prescribed_by' => 'Dr. Somchai',
            'instructions' => 'Take with food',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPlanViaApi(array $overrides = []): string
    {
        return $this->postJson($this->profilesPath().'/medication-plans', $this->planPayload($overrides))
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePlan(array $attributes = [], ?HealthProfile $profile = null): MedicationPlan
    {
        $profile ??= $this->profile;

        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return MedicationPlan::query()->create(array_merge([
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'name' => 'Amlodipine',
            'dosage' => '5 mg',
            'form' => 'tablet',
            'route' => 'oral',
            'doses_per_day' => 2,
            'schedule_times' => ['08:00', '20:00'],
            'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => null,
            'prescribed_by' => null,
            'instructions' => null,
            'status' => MedicationPlan::STATUS_ACTIVE,
            'discontinued_reason' => null,
            'discontinued_at' => null,
            'version' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRecord(
        MedicationPlan $plan,
        array $attributes = [],
        ?HealthProfile $profile = null,
    ): MedicationRecord {
        $profile ??= $this->profile;

        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return MedicationRecord::query()->create(array_merge([
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'plan_id' => $plan->id,
            'recorded_by' => $this->owner->id,
            'source' => 'manual',
            'status' => MedicationRecord::STATUS_TAKEN,
            'scheduled_for' => now()->subHour(),
            'recorded_at' => now(),
            'note' => null,
            'version' => 1,
        ], $attributes));
    }

    public function test_med_t01_owner_creates_a_plan_with_provenance_and_version_one(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->profilesPath().'/medication-plans', $this->planPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Metformin')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.doses_per_day', 2)
            ->assertJsonPath('data.schedule_times', ['08:00', '20:00'])
            ->assertJsonPath('data.recorded_by', $this->owner->id);

        $planId = $response->json('data.id');

        $this->useWorkspace();

        $plan = MedicationPlan::query()->findOrFail($planId);
        $this->assertSame($this->profile->id, $plan->profile_id);
        $this->assertSame($this->owner->id, $plan->recorded_by);
        $this->assertSame('manual', $plan->source);
        $this->assertSame(1, $plan->version);
        $this->assertSame('Take with food', $plan->instructions);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'medication_plan.create',
            'target_id' => $planId,
        ]);
    }

    public function test_med_t02_schedule_rules_reject_a_dose_count_mismatch_duplicates_and_bad_format(): void
    {
        Sanctum::actingAs($this->owner);

        // doses_per_day = 2 but three slots.
        $this->postJson($this->profilesPath().'/medication-plans', $this->planPayload([
            'schedule_times' => ['08:00', '12:00', '20:00'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        // Duplicate slot.
        $this->postJson($this->profilesPath().'/medication-plans', $this->planPayload([
            'schedule_times' => ['08:00', '08:00'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        // Not HH:MM.
        $this->postJson($this->profilesPath().'/medication-plans', $this->planPayload([
            'schedule_times' => ['8:00', '20:00'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->useWorkspace();
        $this->assertSame(0, MedicationPlan::query()->count());
    }

    public function test_med_t03_end_date_before_start_date_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->profilesPath().'/medication-plans', $this->planPayload([
            'starts_on' => '2026-10-10',
            'ends_on' => '2026-10-01',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->useWorkspace();
        $this->assertSame(0, MedicationPlan::query()->count());
    }

    public function test_med_t04_caregiver_with_medications_read_may_read_but_not_create(): void
    {
        $this->makePlan();

        $this->grant($this->caregiver, ['medications'], ['read']);

        Sanctum::actingAs($this->caregiver);

        $this->getJson($this->profilesPath().'/medication-plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson($this->profilesPath().'/medication-plans', $this->planPayload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->useWorkspace();
        $this->assertSame(1, MedicationPlan::query()->count());
    }

    public function test_med_t05_discontinue_requires_a_reason_and_bumps_the_version(): void
    {
        Sanctum::actingAs($this->owner);

        $planId = $this->createPlanViaApi();

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->profilesPath()."/medication-plans/{$planId}/discontinue", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->profilesPath()."/medication-plans/{$planId}/discontinue", [
                'reason' => 'Prescriber stopped the course',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'discontinued')
            ->assertJsonPath('data.discontinued_reason', 'Prescriber stopped the course')
            ->assertJsonPath('data.version', 2);

        $this->useWorkspace();

        $plan = MedicationPlan::query()->findOrFail($planId);
        $this->assertSame(MedicationPlan::STATUS_DISCONTINUED, $plan->status);
        $this->assertSame(2, $plan->version);
        $this->assertNotNull($plan->discontinued_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'medication_plan.discontinue',
            'target_id' => $planId,
        ]);
    }

    public function test_med_t06_a_discontinued_plan_cannot_receive_new_records(): void
    {
        $plan = $this->makePlan([
            'status' => MedicationPlan::STATUS_DISCONTINUED,
            'discontinued_reason' => 'Switched therapy',
            'discontinued_at' => now(),
        ]);

        Sanctum::actingAs($this->owner);

        $this->postJson($this->profilesPath().'/medication-records', [
            'plan_id' => $plan->id,
            'status' => 'taken',
            'recorded_at' => now()->toIso8601String(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'domain_rule');

        $this->useWorkspace();
        $this->assertSame(0, MedicationRecord::query()->count());
    }

    public function test_med_t07_the_same_scheduled_slot_cannot_be_confirmed_twice(): void
    {
        $plan = $this->makePlan();
        $slot = now()->subHours(2)->startOfMinute();

        Sanctum::actingAs($this->owner);

        // A dose slot more than 12 h away from the recorded time is refused by
        // the domain layer before it can reach the unique index.
        $this->postJson($this->profilesPath().'/medication-records', [
            'plan_id' => $plan->id,
            'status' => 'taken',
            'scheduled_for' => now()->subHours(13)->toIso8601String(),
            'recorded_at' => now()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $payload = [
            'plan_id' => $plan->id,
            'status' => 'taken',
            // A self-reported "taken" is always stored as manual, whatever the
            // caller claims about the source.
            'source' => 'device',
            'scheduled_for' => $slot->toIso8601String(),
            'recorded_at' => $slot->copy()->addMinutes(10)->toIso8601String(),
        ];

        $this->postJson($this->profilesPath().'/medication-records', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'taken')
            ->assertJsonPath('data.source', 'manual');

        $this->postJson($this->profilesPath().'/medication-records', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'domain_rule');

        $this->useWorkspace();

        $record = MedicationRecord::query()->where('plan_id', $plan->id)->sole();
        $this->assertSame('manual', $record->source);
        $this->assertSame(1, MedicationRecord::query()->where('plan_id', $plan->id)->count());
    }

    public function test_med_t08_skipped_requires_a_note(): void
    {
        $plan = $this->makePlan();

        Sanctum::actingAs($this->owner);

        $this->postJson($this->profilesPath().'/medication-records', [
            'plan_id' => $plan->id,
            'status' => 'skipped',
            'recorded_at' => now()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->postJson($this->profilesPath().'/medication-records', [
            'plan_id' => $plan->id,
            'status' => 'skipped',
            'recorded_at' => now()->toIso8601String(),
            'note' => 'Felt nauseous after breakfast',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'skipped')
            ->assertJsonPath('data.note', 'Felt nauseous after breakfast');

        $this->useWorkspace();
        $this->assertSame(1, MedicationRecord::query()->count());
    }

    public function test_med_t09_three_day_plan_with_two_daily_doses_and_four_taken_scores_66_7_percent(): void
    {
        $plan = $this->makePlan([
            'starts_on' => now()->subDays(3)->toDateString(),
            'doses_per_day' => 2,
            'schedule_times' => ['08:00', '20:00'],
        ]);

        // Four of the six completed slots are reported as taken.
        foreach ([3, 2] as $daysAgo) {
            foreach ([8, 20] as $hour) {
                $slot = now()->subDays($daysAgo)->setTime($hour, 0);

                $this->makeRecord($plan, [
                    'status' => MedicationRecord::STATUS_TAKEN,
                    'scheduled_for' => $slot,
                    'recorded_at' => $slot->copy()->addMinutes(15),
                ]);
            }
        }

        Sanctum::actingAs($this->owner);

        $response = $this->getJson($this->profilesPath().'/medication-adherence?days=7')
            ->assertOk()
            ->assertJsonPath('meta.days', 7)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plan_id', $plan->id)
            ->assertJsonPath('data.0.expected', 6)
            ->assertJsonPath('data.0.taken', 4)
            ->assertJsonPath('data.0.skipped', 0)
            ->assertJsonPath('data.0.missed', 0)
            ->assertJsonPath('data.0.percent', 66.7);

        // Seven daily buckets, each with its own expected/taken counts.
        $this->assertCount(7, $response->json('data.0.daily'));
        $this->assertSame(6, array_sum(array_column($response->json('data.0.daily'), 'expected')));
    }

    public function test_med_t10_a_stale_if_match_returns_412_and_leaves_the_row_unchanged(): void
    {
        Sanctum::actingAs($this->owner);

        $planId = $this->createPlanViaApi();

        $this->withHeaders(['If-Match' => '99'])
            ->patchJson($this->profilesPath()."/medication-plans/{$planId}", [
                'name' => 'Should not be stored',
            ])
            ->assertStatus(412)
            ->assertJsonPath('code', 'version_conflict');

        $this->useWorkspace();

        $plan = MedicationPlan::query()->findOrFail($planId);
        $this->assertSame('Metformin', $plan->name);
        $this->assertSame(1, $plan->version);
    }

    public function test_med_t11_a_plan_from_another_workspace_is_404(): void
    {
        $foreignPlan = $this->makePlan([], $this->otherWorkspaceProfile);

        $this->useWorkspace();

        Sanctum::actingAs($this->owner);

        // The actor is a member of the first workspace, so the request reaches
        // the domain layer; the plan is scoped to another workspace and must be
        // indistinguishable from "does not exist".
        $this->withHeaders(['If-Match' => '1'])
            ->patchJson($this->profilesPath()."/medication-plans/{$foreignPlan->id}", [
                'name' => 'Hijacked',
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');

        $this->useWorkspace($this->otherWorkspace);

        $plan = MedicationPlan::query()->findOrFail($foreignPlan->id);
        $this->assertSame('Amlodipine', $plan->name);
        $this->assertSame(1, $plan->version);
    }

    public function test_med_t12_free_text_never_reaches_audit_metadata(): void
    {
        Sanctum::actingAs($this->owner);

        $instructionMarker = 'INSTRUCTION-MARKER-4A1F';
        $noteMarker = 'NOTE-MARKER-9C2B';

        $planId = $this->createPlanViaApi(['instructions' => $instructionMarker]);

        $this->postJson($this->profilesPath().'/medication-records', [
            'plan_id' => $planId,
            'status' => 'skipped',
            'recorded_at' => now()->toIso8601String(),
            'note' => $noteMarker,
        ])->assertCreated();

        $this->useWorkspace();

        $business = DB::table('audit_events')->pluck('metadata');
        $security = DB::table('security_audit_logs')->pluck('metadata');

        $this->assertNotEmpty($business);
        $this->assertNotEmpty($security);

        foreach ([...$business, ...$security] as $raw) {
            $raw = (string) $raw;

            $this->assertStringNotContainsString($instructionMarker, $raw);
            $this->assertStringNotContainsString($noteMarker, $raw);

            $metadata = json_decode($raw, true);
            $this->assertIsArray($metadata);
            $this->assertArrayNotHasKey('instructions', $metadata);
            $this->assertArrayNotHasKey('note', $metadata);
        }

        // The values are still readable through the API for authorised readers.
        $this->getJson($this->profilesPath().'/medication-plans?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.instructions', $instructionMarker);
    }

    public function test_med_t13_list_endpoints_are_paginated_and_scoped_to_the_profile(): void
    {
        Sanctum::actingAs($this->owner);

        $planIds = [];

        foreach (['Metformin', 'Amlodipine', 'Atorvastatin'] as $name) {
            $planIds[] = $this->createPlanViaApi(['name' => $name]);
        }

        $this->makePlan(['name' => 'Other profile plan'], $this->sameWorkspaceOtherProfile);

        $firstPage = $this->getJson($this->profilesPath().'/medication-plans?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $cursor = $firstPage->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $secondPage = $this->getJson(
            $this->profilesPath().'/medication-plans?limit=2&cursor='.urlencode($cursor)
        )
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $seen = array_merge(
            array_column($firstPage->json('data'), 'id'),
            array_column($secondPage->json('data'), 'id'),
        );
        sort($seen);

        $expected = $planIds;
        sort($expected);

        $this->assertSame($expected, $seen);

        // Records are paginated and profile-scoped too.
        $this->useWorkspace();
        $plan = MedicationPlan::query()->findOrFail($planIds[0]);

        $this->makeRecord($plan, ['recorded_at' => now()->subHours(3), 'scheduled_for' => null]);
        $this->makeRecord($plan, ['recorded_at' => now()->subHours(2), 'scheduled_for' => null]);
        $this->makeRecord($plan, ['recorded_at' => now()->subHour(), 'scheduled_for' => null]);

        $otherPlan = $this->makePlan([], $this->sameWorkspaceOtherProfile);
        $this->makeRecord($otherPlan, [
            'recorded_at' => now(),
            'scheduled_for' => null,
        ], $this->sameWorkspaceOtherProfile);

        $page = $this->getJson($this->profilesPath().'/medication-records?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotNull($page->json('meta.next_cursor'));

        foreach ($page->json('data') as $row) {
            $this->assertSame($plan->id, $row['plan_id']);
        }

        // The other profile's event is excluded.
        $all = $this->getJson($this->profilesPath().'/medication-records?limit=10')
            ->assertOk()
            ->json('data');

        $this->assertSame([$plan->id], array_values(array_unique(array_column($all, 'plan_id'))));
    }
}
