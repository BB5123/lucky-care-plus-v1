<?php

namespace Tests\Feature\HealthVault;

use App\Modules\HealthRecord\Models\HealthMeasurement;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * HV-T01..T05, T08, T09, T13 — measurement API behaviour.
 */
class MeasurementApiTest extends HealthVaultTestCase
{
    public function test_hv_t01_create_valid_blood_pressure_persists_with_actor_as_recorder(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'blood_pressure',
            'value' => 120,
            'secondary_value' => 80,
            'note' => 'measured at home',
            'recorded_at' => now()->subMinutes(10)->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'blood_pressure')
            ->assertJsonPath('data.unit', 'mmHg')
            ->assertJsonPath('data.context', null)
            ->assertJsonPath('data.version', 1);

        $this->assertEqualsWithDelta(120.0, (float) $response->json('data.value'), 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $response->json('data.secondary_value'), 0.001);

        $this->useWorkspace();

        $measurement = HealthMeasurement::query()->findOrFail($response->json('data.id'));

        $this->assertSame($this->owner->id, $measurement->recorded_by);
        $this->assertSame($this->profile->id, $measurement->profile_id);
        $this->assertSame('manual', $measurement->source);
        $this->assertSame('measured at home', $measurement->note);
        $this->assertSame(1, $measurement->version);
    }

    public function test_hv_t02_blood_pressure_without_diastolic_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'blood_pressure',
            'value' => 120,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->useWorkspace();
        $this->assertSame(0, HealthMeasurement::query()->count());
    }

    public function test_hv_t03_systolic_not_above_diastolic_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'blood_pressure',
            'value' => 80,
            'secondary_value' => 120,
        ])->assertStatus(422)->assertJsonPath('code', 'validation');

        $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'blood_pressure',
            'value' => 120,
            'secondary_value' => 120,
        ])->assertStatus(422);

        $this->useWorkspace();
        $this->assertSame(0, HealthMeasurement::query()->count());
    }

    public function test_hv_t03_out_of_range_glucose_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'blood_glucose',
            'value' => 900,
            'context' => 'fasting',
        ])->assertStatus(422)->assertJsonPath('code', 'validation');

        $this->useWorkspace();
        $this->assertSame(0, HealthMeasurement::query()->count());
    }

    public function test_hv_t03_unknown_type_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'lab_results',
            'value' => 120,
        ])->assertStatus(422)->assertJsonPath('code', 'validation');

        $this->useWorkspace();
        $this->assertSame(0, HealthMeasurement::query()->count());
    }

    public function test_hv_t04_recorded_at_in_the_future_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'heart_rate',
            'value' => 72,
            'recorded_at' => now()->addMinutes(20)->toIso8601String(),
        ])->assertStatus(422)->assertJsonPath('code', 'validation');

        $this->useWorkspace();
        $this->assertSame(0, HealthMeasurement::query()->count());
    }

    public function test_hv_t05_list_is_scoped_and_respects_type_and_date_filters(): void
    {
        $recentBloodPressure = $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 120,
            'secondary_value' => 80,
            'unit' => 'mmHg',
            'recorded_at' => now()->subHour(),
        ]);

        $oldGlucose = $this->makeMeasurement([
            'type' => 'blood_glucose',
            'value' => 110,
            'context' => 'fasting',
            'unit' => 'mg/dL',
            'recorded_at' => now()->subDays(10),
        ]);

        $oldBloodPressure = $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 130,
            'secondary_value' => 85,
            'unit' => 'mmHg',
            'recorded_at' => now()->subDays(40),
        ]);

        // Same workspace, different profile — must never appear.
        $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 150,
            'secondary_value' => 95,
            'unit' => 'mmHg',
            'recorded_at' => now()->subHour(),
        ], $this->sameWorkspaceOtherProfile);

        // Different workspace — must never appear.
        $this->makeMeasurement([
            'type' => 'blood_pressure',
            'value' => 160,
            'secondary_value' => 100,
            'unit' => 'mmHg',
            'recorded_at' => now()->subHour(),
        ], $this->otherWorkspaceProfile);

        Sanctum::actingAs($this->owner);

        $all = $this->getJson($this->profilesPath().'/measurements')->assertOk();
        $allIds = collect($all->json('data'))->pluck('id');

        $this->assertCount(3, $allIds);
        $this->assertTrue($allIds->contains($recentBloodPressure->id));
        $this->assertTrue($allIds->contains($oldGlucose->id));
        $this->assertTrue($allIds->contains($oldBloodPressure->id));

        $byType = $this->getJson($this->profilesPath().'/measurements?type=blood_pressure')->assertOk();
        $typeIds = collect($byType->json('data'))->pluck('id');

        $this->assertCount(2, $typeIds);
        $this->assertTrue($typeIds->contains($recentBloodPressure->id));
        $this->assertTrue($typeIds->contains($oldBloodPressure->id));
        $this->assertFalse($typeIds->contains($oldGlucose->id));

        $from = now()->subDays(11)->toDateString();
        $to = now()->subDays(9)->toDateString();

        $byDate = $this->getJson($this->profilesPath()."/measurements?from={$from}&to={$to}")->assertOk();
        $dateIds = collect($byDate->json('data'))->pluck('id');

        $this->assertCount(1, $dateIds);
        $this->assertSame($oldGlucose->id, $dateIds->first());
    }

    public function test_hv_t08_stale_if_match_returns_412_and_leaves_row_unchanged(): void
    {
        $measurement = $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 72,
            'version' => 1,
        ]);

        Sanctum::actingAs($this->owner);

        $this->withHeaders(['If-Match' => '99'])
            ->patchJson($this->profilesPath()."/measurements/{$measurement->id}", ['value' => 140])
            ->assertStatus(412)
            ->assertJsonPath('code', 'version_conflict');

        $this->useWorkspace();

        $unchanged = HealthMeasurement::query()->findOrFail($measurement->id);
        $this->assertEqualsWithDelta(72.0, (float) $unchanged->value, 0.001);
        $this->assertSame(1, $unchanged->version);
    }

    public function test_hv_t09_correction_bumps_version_and_writes_business_audit(): void
    {
        $measurement = $this->makeMeasurement([
            'type' => 'heart_rate',
            'value' => 72,
            'version' => 1,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->withHeaders(['If-Match' => '1'])
            ->patchJson($this->profilesPath()."/measurements/{$measurement->id}", [
                'value' => 86,
                'note' => 'after walking',
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertEqualsWithDelta(86.0, (float) $response->json('data.value'), 0.001);

        $this->useWorkspace();

        $updated = HealthMeasurement::query()->findOrFail($measurement->id);
        $this->assertSame(2, $updated->version);
        $this->assertSame('after walking', $updated->note);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'health_measurement.correct',
            'target_type' => 'health_measurement',
            'target_id' => $measurement->id,
            'result' => 'allow',
        ]);
    }

    public function test_hv_t13_note_never_appears_in_audit_metadata(): void
    {
        $note = 'secret-note-hv-t13-do-not-log';

        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'heart_rate',
            'value' => 70,
            'note' => $note,
        ])->assertCreated();

        $this->useWorkspace();

        $stored = HealthMeasurement::query()->findOrFail($response->json('data.id'));
        $this->assertSame($note, $stored->note, 'The note must be persisted (and decrypted by the model cast).');

        $securityMetadata = DB::table('security_audit_logs')->pluck('metadata');
        $this->assertNotEmpty($securityMetadata);

        foreach ($securityMetadata as $metadata) {
            $this->assertStringNotContainsString($note, (string) $metadata);
        }

        $businessMetadata = DB::table('audit_events')->pluck('metadata');
        $this->assertNotEmpty($businessMetadata);

        foreach ($businessMetadata as $metadata) {
            $this->assertStringNotContainsString($note, (string) $metadata);
        }
    }
}
