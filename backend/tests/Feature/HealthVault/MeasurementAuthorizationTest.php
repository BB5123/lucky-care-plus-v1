<?php

namespace Tests\Feature\HealthVault;

use App\Modules\HealthRecord\Application\HealthMeasurementService;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use Laravel\Sanctum\Sanctum;

/**
 * HV-T06, T07, T10 — measurement authorization and deletion semantics.
 */
class MeasurementAuthorizationTest extends HealthVaultTestCase
{
    public function test_hv_t06_grant_without_vitals_scope_denies_read_and_create(): void
    {
        $this->grant(
            $this->caregiver,
            ['profile.basic'],
            ['read', 'create'],
        );

        Sanctum::actingAs($this->caregiver);

        $this->getJson($this->profilesPath().'/measurements')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->postJson($this->profilesPath().'/measurements', [
            'type' => 'heart_rate',
            'value' => 72,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->useWorkspace();
        $this->assertSame(0, HealthMeasurement::query()->count());
    }

    public function test_hv_t07_profile_from_another_workspace_is_404_without_existence_leak(): void
    {
        Sanctum::actingAs($this->owner);

        // The actor owns a profile in the main workspace, but the route points
        // at a profile that lives in another workspace of the same tenant.
        $this->getJson($this->profilesPath($this->otherWorkspaceProfile).'/measurements')
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    public function test_hv_t10_delete_is_denied_by_baseline_and_the_row_survives(): void
    {
        // ABD-011 / DATA_ACCESS_MATRIX Matrix B: "Delete measurement" is
        // D by default for every role, so RoleGate refuses the permission
        // before the controller runs.
        $measurement = $this->makeMeasurement();

        Sanctum::actingAs($this->owner);

        $this->withHeaders(['If-Match' => '1'])
            ->deleteJson($this->profilesPath()."/measurements/{$measurement->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->useWorkspace();

        $row = HealthMeasurement::withTrashed()->findOrFail($measurement->id);
        $this->assertNull($row->deleted_at);
    }

    public function test_hv_t10_soft_delete_keeps_the_row_and_removes_it_from_the_list(): void
    {
        $measurement = $this->makeMeasurement();

        app(HealthMeasurementService::class)->delete(
            $this->profile,
            $measurement->id,
            $measurement->version,
            $this->owner,
        );

        $this->useWorkspace();

        $row = HealthMeasurement::withTrashed()->findOrFail($measurement->id);
        $this->assertNotNull($row->deleted_at, 'DM-R01 requires a soft delete, not a hard delete.');

        Sanctum::actingAs($this->owner);

        $list = $this->getJson($this->profilesPath().'/measurements')->assertOk();
        $this->assertSame([], $list->json('data'));

        // The domain audit row records the soft delete.
        $this->useWorkspace();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'health_measurement.delete',
            'target_id' => $measurement->id,
        ]);
    }
}
