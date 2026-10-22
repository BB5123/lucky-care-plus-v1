<?php

namespace Tests\Feature\CareTask;

use App\Models\User;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\Consent\Models\AccessGrant;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * Fixtures for the P1.3 acceptance suite.
 *
 * Two workspaces under one tenant prove cross-workspace isolation. The
 * dependent profile is deliberately *not* owned by any of the caregivers, so a
 * test can distinguish "assigned to the task" from "allowed to read the
 * health record" (F-04 / CRM-006).
 */
abstract class CareTaskTestCase extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Workspace $workspace;

    protected Workspace $otherWorkspace;

    protected User $owner;

    protected User $caregiver;

    protected User $helper;

    protected User $verifier;

    protected HealthProfile $profile;

    protected HealthProfile $dependentProfile;

    protected HealthProfile $otherWorkspaceProfile;

    protected Person $ownerPerson;

    protected Person $dependentPerson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('ct-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'CT Workspace');
        $this->otherWorkspace = $this->createWorkspace($this->tenant, 'CT Other Workspace');

        $this->owner = User::factory()->create();
        $this->caregiver = User::factory()->create();
        $this->helper = User::factory()->create();
        $this->verifier = User::factory()->create();

        $this->addMember($this->workspace, $this->owner, 'profile_owner');
        $this->addMember($this->workspace, $this->caregiver, 'caregiver');
        $this->addMember($this->workspace, $this->helper, 'caregiver');
        $this->addMember($this->workspace, $this->verifier, 'caregiver');
        $this->addMember($this->otherWorkspace, $this->owner, 'profile_owner');

        $this->useWorkspace($this->workspace);

        $this->ownerPerson = Person::forceCreate([
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
            'person_id' => $this->ownerPerson->id,
            'version' => 1,
        ]);

        $this->dependentPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'user_id' => null,
            'display_name' => 'Dependent Person',
            'managed_by_person_id' => $this->ownerPerson->id,
        ]);

        $this->dependentProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $this->dependentPerson->id,
            'managed_by_person_id' => $this->ownerPerson->id,
            'version' => 1,
        ]);

        $this->useWorkspace($this->otherWorkspace);

        $otherPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'user_id' => null,
            'display_name' => 'Other Workspace Person',
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

    protected function useWorkspace(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::set($this->tenant->id, $workspace->id);
    }

    protected function tasksPath(?string $taskId = null, ?Workspace $workspace = null): string
    {
        $workspace ??= $this->workspace;

        $path = "/api/v1/workspaces/{$workspace->id}/care-tasks";

        return $taskId === null ? $path : "{$path}/{$taskId}";
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $actions
     */
    protected function grant(
        User $grantee,
        array $scopes,
        array $actions,
        ?HealthProfile $profile = null,
    ): AccessGrant {
        $profile ??= $this->profile;
        $this->useWorkspace();

        return AccessGrant::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $profile->id,
            'granted_by' => $this->owner->id,
            'grantee_user_id' => $grantee->id,
            'resource_scope' => $scopes,
            'allowed_actions' => $actions,
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
    protected function payload(array $attributes = []): array
    {
        return array_merge([
            'profile_id' => $this->profile->id,
            'tier' => CareTask::TIER_DELEGATED,
            'type' => 'DAILY_CARE',
            'title' => 'Help with breakfast',
            'description' => 'Warm the food and sit with them while they eat.',
            'priority' => 'normal',
            'due_at' => now()->addHour()->toIso8601String(),
        ], $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function createTaskViaApi(array $attributes = []): array
    {
        return $this->postJson($this->tasksPath(), $this->payload($attributes))
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTask(array $attributes = [], ?HealthProfile $profile = null): CareTask
    {
        $profile ??= $this->profile;

        // The fixture may belong to another workspace (cross-workspace test),
        // so bind the RLS context to the profile's own workspace.
        TenantContext::set($profile->tenant_id, $profile->workspace_id);

        return CareTask::query()->create(array_merge([
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'created_by' => $this->owner->id,
            'tier' => CareTask::TIER_DELEGATED,
            'type' => 'DAILY_CARE',
            'title' => 'Directly created task',
            'description' => null,
            'priority' => 'normal',
            'scheduled_for' => null,
            'due_at' => now()->addHour(),
            'timezone' => 'Asia/Bangkok',
            'status' => CareTask::STATUS_OPEN,
            'outcome' => null,
            'verification' => CareTask::VERIFICATION_NOT_REQUIRED,
            'verification_required' => false,
            'evidence_policy' => CareTask::EVIDENCE_NONE,
            'escalation_level' => 0,
            'blocked_reason' => null,
            'completed_at' => null,
            'idempotency_key' => null,
            'version' => 1,
            'source' => 'manual',
        ], $attributes));
    }

    protected function makePrimaryAssignment(CareTask $task, User $user): TaskAssignment
    {
        $this->useWorkspace();

        return TaskAssignment::query()->create([
            'tenant_id' => $task->tenant_id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'role' => TaskAssignment::ROLE_PRIMARY,
            'can_complete' => false,
            'assigned_by' => $this->owner->id,
        ]);
    }
}
