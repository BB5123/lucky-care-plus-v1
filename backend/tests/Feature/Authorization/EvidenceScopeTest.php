<?php

namespace Tests\Feature\Authorization;

use App\Modules\Authz\Authorizer;
use App\Modules\Authz\PermissionCatalog;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvidence;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Support\Id\Uuid7;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\CareTask\CareTaskTestCase;

/**
 * EVD-T01 ... EVD-T05 — CR-004 / baseline v1.3.0.
 *
 * `care_task.read` and `care_task.evidence.read` used to share the `tasks`
 * scope, so a `tasks:read` grant could read the PHI-bearing evidence attached
 * to a task. Evidence reads now require `task_evidence:read`; attaching
 * evidence remains on `tasks:create`.
 */
class EvidenceScopeTest extends CareTaskTestCase
{
    public function test_evd_t01_tasks_read_without_task_evidence_can_read_the_task_but_not_evidence(): void
    {
        $task = $this->makeTask([
            'profile_id' => $this->dependentProfile->id,
        ], $this->dependentProfile);

        $this->makePrimaryAssignment($task, $this->caregiver);
        $this->grant($this->caregiver, ['tasks'], ['read'], $this->dependentProfile);
        $this->addNoteEvidence($task);

        Sanctum::actingAs($this->caregiver);

        // The task itself is readable with `tasks:read`.
        $this->getJson($this->tasksPath($task->id))
            ->assertOk()
            ->assertJsonPath('data.id', $task->id);

        // Evidence is a separate authority and is denied.
        $this->getJson($this->tasksPath($task->id).'/evidence')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_evd_t02_adding_task_evidence_read_to_the_same_grant_allows_evidence(): void
    {
        $task = $this->makeTask([
            'profile_id' => $this->dependentProfile->id,
        ], $this->dependentProfile);

        $this->makePrimaryAssignment($task, $this->caregiver);
        $grant = $this->grant($this->caregiver, ['tasks'], ['read'], $this->dependentProfile);
        $this->addNoteEvidence($task);

        Sanctum::actingAs($this->caregiver);

        $this->getJson($this->tasksPath($task->id).'/evidence')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->useWorkspace();
        $grant->update([
            'resource_scope' => ['tasks', 'task_evidence'],
            'allowed_actions' => ['read'],
        ]);

        $this->getJson($this->tasksPath($task->id).'/evidence')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', TaskEvidence::TYPE_NOTE);
    }

    public function test_evd_t03_evidence_create_still_works_with_tasks_create_only(): void
    {
        $task = $this->makeTask([
            'profile_id' => $this->dependentProfile->id,
        ], $this->dependentProfile);

        $this->makePrimaryAssignment($task, $this->caregiver);
        $this->grant($this->caregiver, ['tasks'], ['create'], $this->dependentProfile);

        Sanctum::actingAs($this->caregiver);

        $this->postJson($this->tasksPath($task->id).'/evidence', [
            'type' => TaskEvidence::TYPE_VALUE,
            'metric' => 'blood_glucose',
            'value' => 112,
            'unit' => 'mg/dL',
        ])->assertCreated();

        $this->useWorkspace();
        $this->assertSame(
            1,
            TaskEvidence::query()->where('task_id', $task->id)->count(),
            'Attaching proof is part of doing the task and must not need task_evidence:read.',
        );
    }

    public function test_evd_t04_has_photo_evidence_is_visible_without_task_evidence(): void
    {
        $task = $this->makeTask([
            'profile_id' => $this->dependentProfile->id,
        ], $this->dependentProfile);

        $this->makePrimaryAssignment($task, $this->caregiver);
        $this->grant($this->caregiver, ['tasks'], ['read'], $this->dependentProfile);
        $this->addPhotoEvidence($task);

        Sanctum::actingAs($this->caregiver);

        $this->getJson($this->tasksPath($task->id))
            ->assertOk()
            ->assertJsonPath('data.has_photo_evidence', true);

        // The flag is task metadata; the evidence content is still denied.
        $this->getJson($this->tasksPath($task->id).'/evidence')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_evd_t05_task_evidence_is_a_declared_scope_and_the_doc_agrees(): void
    {
        $catalog = new PermissionCatalog;

        $this->assertContains('task_evidence', $catalog->scopes());
        $this->assertTrue($catalog->isScopeEnabled('task_evidence'));
        $this->assertSame(
            ['scope' => 'task_evidence', 'action' => 'read'],
            app(Authorizer::class)->mappingFor('care_task.evidence.read'),
        );

        // Mirror PermissionCatalogTest's drift guard for the scope list: the
        // .ai document and the config must agree, or a later edit can quietly
        // remove the vocabulary while the code still emits the token.
        $markdown = file_get_contents(
            dirname(base_path()).DIRECTORY_SEPARATOR.'.ai'.DIRECTORY_SEPARATOR.'03-AUTHORIZATION.md',
        );

        $this->assertIsString($markdown, '03-AUTHORIZATION.md must be readable');

        $start = strpos($markdown, '## 5. Scope model');
        $end = strpos($markdown, '## 6.', (int) $start);
        $section = $start === false || $end === false ? '' : substr($markdown, $start, $end - $start);

        $this->assertMatchesRegularExpression('/^\|\s*`task_evidence`\s*\|/m', $section);
    }

    private function addNoteEvidence(CareTask $task): void
    {
        $this->useWorkspace();

        TaskEvidence::query()->create([
            'tenant_id' => $task->tenant_id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'type' => TaskEvidence::TYPE_NOTE,
            'note' => 'Hidden evidence note',
            'created_by' => $this->owner->id,
        ]);
    }

    private function addPhotoEvidence(CareTask $task): void
    {
        $profile = HealthProfile::query()->findOrFail($task->profile_id);
        $this->useWorkspace();

        $documentId = Uuid7::generate();

        MedicalDocument::query()->forceCreate([
            'id' => $documentId,
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'uploaded_by' => $this->owner->id,
            'kind' => 'photo',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 6,
            'sha256' => hash('sha256', 'photo-bytes'),
            'storage_disk' => 'local',
            'storage_path' => 'documents/'.$profile->tenant_id.'/'.$documentId,
            'original_name' => 'evidence.jpg',
            'captured_at' => null,
            'scan_status' => MedicalDocument::SCAN_SKIPPED,
            'status' => MedicalDocument::STATUS_AVAILABLE,
            'version' => 1,
        ]);

        TaskEvidence::query()->create([
            'tenant_id' => $task->tenant_id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'type' => TaskEvidence::TYPE_PHOTO,
            'document_id' => $documentId,
            'created_by' => $this->owner->id,
        ]);
    }
}
