<?php

namespace Tests\Feature\HealthVault;

use App\Modules\HealthRecord\Models\Allergy;
use App\Modules\HealthRecord\Models\HealthCondition;
use Laravel\Sanctum\Sanctum;

/**
 * HV-T12 — conditions/allergies creation requires the right permission.
 */
class ConditionsAllergiesTest extends HealthVaultTestCase
{
    public function test_hv_t12_owner_can_create_conditions_and_allergies(): void
    {
        Sanctum::actingAs($this->owner);

        $conditionId = $this->postJson($this->profilesPath().'/conditions', [
            'code' => 'I10',
            'name' => 'Hypertension',
            'diagnosed_at' => '2020-01-02',
            'status' => 'active',
            'notes' => 'monitor monthly',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'I10')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 1)
            ->json('data.id');

        $allergyId = $this->postJson($this->profilesPath().'/allergies', [
            'substance' => 'Penicillin',
            'reaction' => 'rash',
            'severity' => 'moderate',
            'noted_at' => '2019-05-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.substance', 'Penicillin')
            ->assertJsonPath('data.severity', 'moderate')
            ->assertJsonPath('data.version', 1)
            ->json('data.id');

        $this->useWorkspace();

        $condition = HealthCondition::query()->findOrFail($conditionId);
        $this->assertSame($this->profile->id, $condition->profile_id);
        $this->assertSame('monitor monthly', $condition->notes);
        // Provenance is mandatory for every health-data row (.ai/06 §3).
        $this->assertSame($this->owner->id, $condition->recorded_by);
        $this->assertSame('manual', $condition->source);

        $allergy = Allergy::query()->findOrFail($allergyId);
        $this->assertSame($this->profile->id, $allergy->profile_id);
        $this->assertSame('moderate', $allergy->severity);
        $this->assertSame($this->owner->id, $allergy->recorded_by);
        $this->assertSame('manual', $allergy->source);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'health_condition.create',
            'target_id' => $conditionId,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'allergy.update',
            'target_id' => $allergyId,
        ]);
    }

    public function test_hv_t12_viewer_cannot_create_conditions_or_allergies(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson($this->profilesPath().'/conditions', [
            'name' => 'Asthma',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->postJson($this->profilesPath().'/allergies', [
            'substance' => 'Peanuts',
            'severity' => 'severe',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->useWorkspace();

        $this->assertSame(0, HealthCondition::query()->count());
        $this->assertSame(0, Allergy::query()->count());
    }

    public function test_hv_t12_owner_can_list_and_update_conditions_with_optimistic_locking(): void
    {
        Sanctum::actingAs($this->owner);

        $conditionId = $this->postJson($this->profilesPath().'/conditions', [
            'name' => 'Type 2 diabetes',
            'status' => 'active',
        ])
            ->assertCreated()
            ->json('data.id');

        $this->getJson($this->profilesPath().'/conditions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conditionId);

        $this->withHeaders(['If-Match' => '99'])
            ->patchJson($this->profilesPath()."/conditions/{$conditionId}", ['status' => 'resolved'])
            ->assertStatus(412)
            ->assertJsonPath('code', 'version_conflict');

        $this->withHeaders(['If-Match' => '1'])
            ->patchJson($this->profilesPath()."/conditions/{$conditionId}", [
                'status' => 'resolved',
                'notes' => 'resolved in 2026',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.version', 2);

        $this->useWorkspace();

        $condition = HealthCondition::query()->findOrFail($conditionId);
        $this->assertSame('resolved', $condition->status);
        $this->assertSame('resolved in 2026', $condition->notes);
        $this->assertSame(2, $condition->version);
    }
}
