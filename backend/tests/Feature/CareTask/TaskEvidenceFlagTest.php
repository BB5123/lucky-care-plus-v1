<?php

namespace Tests\Feature\CareTask;

use App\Models\User;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskEvidence;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Support\Id\Uuid7;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * FLG-T01 ... FLG-T05 — the derived `has_photo_evidence` flag.
 *
 * The flag is plain task metadata: a photo row with a document. It is safe on
 * the need-to-know card and must be available without `care_task.evidence.read`,
 * but it must never carry the document id, filename or note.
 */
class TaskEvidenceFlagTest extends CareTaskTestCase
{
    public function test_flg_t01_a_photo_row_with_a_document_reports_true_and_no_row_reports_false(): void
    {
        $withPhoto = $this->makeTask(['title' => 'With photo']);
        $withoutPhoto = $this->makeTask(['title' => 'Without photo']);

        $this->addPhotoEvidence($withPhoto);

        Sanctum::actingAs($this->owner);

        $this->getJson($this->tasksPath($withPhoto->id))
            ->assertOk()
            ->assertJsonPath('data.has_photo_evidence', true);

        $this->getJson($this->tasksPath($withoutPhoto->id))
            ->assertOk()
            ->assertJsonPath('data.has_photo_evidence', false);
    }

    public function test_flg_t02_value_or_note_evidence_does_not_count_as_a_photo(): void
    {
        $valueTask = $this->makeTask(['title' => 'Value only']);
        $noteTask = $this->makeTask(['title' => 'Note only']);

        $this->useWorkspace();
        TaskEvidence::query()->create([
            'tenant_id' => $valueTask->tenant_id,
            'workspace_id' => $valueTask->workspace_id,
            'task_id' => $valueTask->id,
            'type' => TaskEvidence::TYPE_VALUE,
            'metric' => 'blood_glucose',
            'value' => 112,
            'unit' => 'mg/dL',
            'created_by' => $this->owner->id,
        ]);
        TaskEvidence::query()->create([
            'tenant_id' => $noteTask->tenant_id,
            'workspace_id' => $noteTask->workspace_id,
            'task_id' => $noteTask->id,
            'type' => TaskEvidence::TYPE_NOTE,
            'note' => 'A note is not a photo',
            'created_by' => $this->owner->id,
        ]);

        Sanctum::actingAs($this->owner);

        $this->getJson($this->tasksPath($valueTask->id))
            ->assertOk()
            ->assertJsonPath('data.has_photo_evidence', false);

        $this->getJson($this->tasksPath($noteTask->id))
            ->assertOk()
            ->assertJsonPath('data.has_photo_evidence', false);
    }

    public function test_flg_t03_the_safe_card_carries_the_flag_without_description_evidence_or_document_ids(): void
    {
        $task = $this->makeTask([
            'profile_id' => $this->dependentProfile->id,
            'description' => 'SECRET-DESCRIPTION-MARKER',
        ], $this->dependentProfile);

        $this->makePrimaryAssignment($task, $this->caregiver);
        $document = $this->makeDocument($this->dependentProfile, 'SECRET-FILENAME-MARKER.jpg');
        $this->addPhotoEvidence($task, $document, 'SECRET-EVIDENCE-NOTE');

        Sanctum::actingAs($this->caregiver);

        $card = $this->getJson($this->tasksPath($task->id))
            ->assertOk()
            ->json('data');

        $this->assertTrue($card['has_photo_evidence']);
        $this->assertArrayNotHasKey('description', $card);
        $this->assertArrayNotHasKey('evidence', $card);
        $this->assertArrayNotHasKey('document_id', $card);

        $encoded = json_encode($card, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($document->id, $encoded);
        $this->assertStringNotContainsString('SECRET-DESCRIPTION-MARKER', $encoded);
        $this->assertStringNotContainsString('SECRET-EVIDENCE-NOTE', $encoded);
        $this->assertStringNotContainsString('SECRET-FILENAME-MARKER.jpg', $encoded);

        $list = $this->getJson($this->tasksPath())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['has_photo_evidence']);
        $this->assertArrayNotHasKey('description', $list[0]);
        $this->assertArrayNotHasKey('evidence', $list[0]);
    }

    public function test_flg_t04_listing_many_tasks_uses_one_exists_subquery_not_one_query_per_task(): void
    {
        for ($index = 1; $index <= 8; $index++) {
            $task = $this->makeTask(['title' => "Photo task {$index}"]);
            $this->addPhotoEvidence($task);
        }

        Sanctum::actingAs($this->owner);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $data = $this->getJson($this->tasksPath())
            ->assertOk()
            ->json('data');

        $this->assertCount(8, $data);
        $this->assertSame(
            [true],
            array_values(array_unique(array_column($data, 'has_photo_evidence'))),
            'Every task with a photo row must report the flag.',
        );

        // The eager `withExists` keeps the evidence lookup inside the task
        // SELECT. An N+1 implementation would issue one extra
        // `select * from "task_evidence"` per task.
        $evidenceQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "task_evidence"'),
        ));

        $this->assertLessThanOrEqual(
            1,
            count($evidenceQueries),
            'Listing tasks must not query task_evidence once per task: '.implode("\n", $evidenceQueries),
        );
        // The request pipeline (auth, workspace resolution, membership, RLS
        // context) adds a fixed cost; the assertion stays independent of the
        // task count. With an N+1 flag lookup the evidence queries above would
        // already be 8, not 1.
        $this->assertLessThanOrEqual(
            40,
            count($queries),
            'Listing tasks issued an unbounded number of queries: '.count($queries),
        );
    }

    public function test_flg_t05_a_viewer_can_read_the_flag_while_evidence_content_stays_denied(): void
    {
        $viewer = User::factory()->create();
        $this->addMember($this->workspace, $viewer, 'viewer');

        $task = $this->makeTask([
            'profile_id' => $this->dependentProfile->id,
        ], $this->dependentProfile);

        $this->makePrimaryAssignment($task, $viewer);
        $this->addPhotoEvidence($task);

        Sanctum::actingAs($viewer);

        // `care_task.read` is enough for the task payload (the safe card, since
        // the viewer holds no profile grant).
        $this->getJson($this->tasksPath($task->id))
            ->assertOk()
            ->assertJsonPath('data.has_photo_evidence', true);

        // Reading the evidence itself is a different authority and is denied.
        $this->getJson($this->tasksPath($task->id).'/evidence')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    private function makeDocument(
        ?HealthProfile $profile = null,
        string $originalName = 'evidence.jpg',
    ): MedicalDocument {
        $profile ??= $this->profile;
        $this->useWorkspace();

        $id = Uuid7::generate();

        return MedicalDocument::query()->forceCreate([
            'id' => $id,
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'uploaded_by' => $this->owner->id,
            'kind' => 'photo',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 6,
            'sha256' => hash('sha256', 'photo-bytes'),
            'storage_disk' => 'local',
            'storage_path' => 'documents/'.$profile->tenant_id.'/'.$id,
            'original_name' => $originalName,
            'captured_at' => null,
            'scan_status' => MedicalDocument::SCAN_SKIPPED,
            'status' => MedicalDocument::STATUS_AVAILABLE,
            'version' => 1,
        ]);
    }

    private function addPhotoEvidence(
        CareTask $task,
        ?MedicalDocument $document = null,
        ?string $note = null,
    ): TaskEvidence {
        $document ??= $this->makeDocument(
            HealthProfile::query()->findOrFail($task->profile_id),
        );

        $this->useWorkspace();

        return TaskEvidence::query()->create([
            'tenant_id' => $task->tenant_id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'type' => TaskEvidence::TYPE_PHOTO,
            'metric' => null,
            'value' => null,
            'unit' => null,
            'document_id' => $document->id,
            'note' => $note,
            'created_by' => $this->owner->id,
        ]);
    }
}
