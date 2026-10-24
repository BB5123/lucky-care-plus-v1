<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Modules\CareCrm\Models\CareTask;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\CareTask\CareTaskTestCase;

/**
 * TPL-T01 ... TPL-T04 — CR-003 (baseline v1.2.0).
 *
 * Task templates and escalation policy are their own authority (ABD-017).
 * These tests prove the role gate and the `tasks` scope are both applied, and
 * that the old borrowed IDs (`care_task.read` / `care_task.create` /
 * `workspace.read` / `workspace.update`) no longer gate the surface.
 *
 * TPL-T05 (every new route is permission-guarded) lives in
 * {@see RoutePermissionCoverageTest}, next to the route inventory it guards.
 */
class TemplatePermissionTest extends CareTaskTestCase
{
    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->viewer = User::factory()->create();

        $this->addMember($this->workspace, $this->admin, 'workspace_admin');
        $this->addMember($this->workspace, $this->viewer, 'viewer');

        $this->useWorkspace();
    }

    // -- TPL-T01 ------------------------------------------------------------

    public function test_tpl_t01_caregiver_read_is_grant_scoped_and_creation_is_conditional(): void
    {
        Sanctum::actingAs($this->caregiver);

        // The role may read (Matrix C = A), but the `tasks` scope is still
        // required because this is another person's profile (F-04).
        $this->getJson($this->templatePath())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->grant($this->caregiver, ['tasks'], ['read']);

        $this->getJson($this->templatePath())
            ->assertOk()
            ->assertJsonPath('data', []);

        // Creating a routine is `C` for a caregiver: a read-only grant is not
        // enough, so the request is denied by the scope check.
        $this->postJson($this->templatePath(), $this->templatePayload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        // With `tasks:create`, the conditional cell is satisfied.
        $this->grant($this->caregiver, ['tasks'], ['create']);

        $this->postJson($this->templatePath(), $this->templatePayload())
            ->assertCreated();
    }

    // -- TPL-T02 ------------------------------------------------------------

    public function test_tpl_t02_viewer_is_denied_template_read(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->getJson($this->templatePath())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->postJson($this->templatePath(), $this->templatePayload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    // -- TPL-T03 ------------------------------------------------------------

    public function test_tpl_t03_workspace_admin_can_create_update_and_deactivate(): void
    {
        // Workspace administration is not profile ownership, so the admin
        // still needs the `tasks` scope on the target profile.
        $this->grant($this->admin, ['tasks'], ['read', 'create', 'update']);

        Sanctum::actingAs($this->admin);

        $template = $this->postJson($this->templatePath(), $this->templatePayload([
            'assigned_user_id' => $this->admin->id,
        ]))
            ->assertCreated()
            ->json('data');

        $this->withHeaders(['If-Match' => '1'])
            ->patchJson($this->templatePath($template['id']), ['title' => 'Updated routine'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated routine')
            ->assertJsonPath('data.version', 2);

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->templatePath($template['id']).'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.version', 3);
    }

    // -- TPL-T04 ------------------------------------------------------------

    public function test_tpl_t04_escalation_get_requires_read_and_put_requires_update(): void
    {
        Sanctum::actingAs($this->caregiver);

        $this->getJson($this->escalationPath())
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->putJson($this->escalationPath(), ['policies' => [$this->policyPayload()]])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs($this->admin);

        // The admin also needs a profile scope only for templates; escalation
        // is workspace-level, so the role gate is the whole decision here.
        $this->putJson($this->escalationPath(), ['policies' => [$this->policyPayload()]])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // -- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function templatePayload(array $attributes = []): array
    {
        return array_merge([
            'title' => 'Morning blood pressure',
            'tier' => CareTask::TIER_ROUTINE,
            'type' => 'VITAL_MEASUREMENT',
            'priority' => 'normal',
            'frequency' => 'daily',
            'interval' => 1,
            'times_of_day' => ['08:00'],
            'timezone' => 'Asia/Bangkok',
            'starts_on' => '2026-03-02',
            'ends_on' => null,
            'evidence_policy' => 'none',
            'verification_required' => false,
            'assignee_strategy' => 'fixed',
            'assigned_user_id' => $this->owner->id,
        ], $attributes);
    }

    private function templatePath(?string $templateId = null): string
    {
        $path = "/api/v1/workspaces/{$this->workspace->id}/profiles/{$this->profile->id}/task-templates";

        return $templateId === null ? $path : "{$path}/{$templateId}";
    }

    private function escalationPath(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/escalation-policies";
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function policyPayload(array $attributes = []): array
    {
        return array_merge([
            'level' => 1,
            'after_minutes' => 30,
            'notify_assignee' => true,
            'notify_roles' => [],
            'action' => 'notify',
            'is_active' => true,
        ], $attributes);
    }
}
