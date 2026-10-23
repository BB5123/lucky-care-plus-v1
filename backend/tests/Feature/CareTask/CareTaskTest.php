<?php

namespace Tests\Feature\CareTask;

use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\CareCrm\Models\TaskEvidence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * P1.3 acceptance suite — CT-T01 ... CT-T16.
 *
 * The state machine is deliberately per-tier (ADR-001), so each test exercises
 * the rule through the HTTP surface rather than asserting the class in
 * isolation.
 */
class CareTaskTest extends CareTaskTestCase
{
    public function test_ct_t01_a_routine_task_cannot_be_accepted(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi(['tier' => CareTask::TIER_ROUTINE]);

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->tasksPath($task['id']).'/accept')
            ->assertStatus(409)
            ->assertJsonPath('code', 'domain_rule');
    }

    public function test_ct_t02_completing_a_routine_task_records_outcome_and_completed_at(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi(['tier' => CareTask::TIER_ROUTINE]);

        $response = $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->tasksPath($task['id']).'/complete', ['outcome' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.outcome', 'done')
            ->assertJsonPath('data.verification', 'not_required')
            ->assertJsonPath('data.version', 2);

        $this->assertNotNull($response->json('data.completed_at'));

        $this->useWorkspace();
        $stored = CareTask::query()->findOrFail($task['id']);
        $this->assertSame(CareTask::STATUS_COMPLETED, $stored->status);
        $this->assertSame('done', $stored->outcome);
        $this->assertNotNull($stored->completed_at);
    }

    public function test_ct_t03_verify_by_the_completing_user_is_forbidden(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'tier' => CareTask::TIER_CLINICAL,
            'assignee_user_id' => $this->caregiver->id,
            'evidence_policy' => 'value',
        ]);

        Sanctum::actingAs($this->caregiver);

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->tasksPath($task['id']).'/start')
            ->assertOk();

        $this->postJson($this->tasksPath($task['id']).'/evidence', [
            'type' => 'value',
            'metric' => 'blood_glucose',
            'value' => 112,
            'unit' => 'mg/dL',
        ])->assertCreated();

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->tasksPath($task['id']).'/complete', ['outcome' => 'done'])
            ->assertOk();

        $this->useWorkspace();
        $stored = CareTask::query()->findOrFail($task['id']);
        $this->assertSame(CareTask::STATUS_COMPLETED, $stored->status);
        $this->assertSame(CareTask::VERIFICATION_PENDING, $stored->verification);

        $this->withHeaders(['If-Match' => '3'])
            ->postJson($this->tasksPath($task['id']).'/verify')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_ct_t04_a_non_assignee_without_can_complete_cannot_complete_a_delegated_task(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();
        $this->assign($task['id'], $this->caregiver->id, '1');

        Sanctum::actingAs($this->caregiver);
        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->tasksPath($task['id']).'/accept')
            ->assertOk();
        $this->withHeaders(['If-Match' => '3'])
            ->postJson($this->tasksPath($task['id']).'/start')
            ->assertOk();

        Sanctum::actingAs($this->helper);

        $this->withHeaders(['If-Match' => '4'])
            ->postJson($this->tasksPath($task['id']).'/complete', ['outcome' => 'done'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_ct_t05_clinical_completion_without_required_evidence_is_422(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'tier' => CareTask::TIER_CLINICAL,
            'assignee_user_id' => $this->caregiver->id,
            'evidence_policy' => 'value',
        ]);

        Sanctum::actingAs($this->caregiver);

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->tasksPath($task['id']).'/start')
            ->assertOk();

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->tasksPath($task['id']).'/complete', ['outcome' => 'done'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');
    }

    public function test_ct_t06_full_clinical_happy_path(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'tier' => CareTask::TIER_CLINICAL,
            'assignee_user_id' => $this->caregiver->id,
            'evidence_policy' => 'value',
        ]);

        $this->assertSame('assigned', $task['status']);

        Sanctum::actingAs($this->caregiver);
        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->tasksPath($task['id']).'/start')
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->postJson($this->tasksPath($task['id']).'/evidence', [
            'type' => 'value',
            'metric' => 'blood_pressure_systolic',
            'value' => 128,
            'unit' => 'mmHg',
        ])->assertCreated();

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->tasksPath($task['id']).'/complete', ['outcome' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // The assignee has no profile grant, so transition responses use the
        // need-to-know card; verify the verification column in the database.
        $this->useWorkspace();
        $this->assertSame(
            CareTask::VERIFICATION_PENDING,
            CareTask::query()->findOrFail($task['id'])->verification,
        );

        Sanctum::actingAs($this->verifier);

        $this->withHeaders(['If-Match' => '3'])
            ->postJson($this->tasksPath($task['id']).'/verify')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->useWorkspace();
        $verified = CareTask::query()->findOrFail($task['id']);
        $this->assertSame(CareTask::VERIFICATION_VERIFIED, $verified->verification);
        $this->assertSame(4, $verified->version);
    }

    public function test_ct_t07_rejected_task_reopens_to_in_progress_with_the_reason_recorded(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'tier' => CareTask::TIER_CLINICAL,
            'assignee_user_id' => $this->caregiver->id,
        ]);

        Sanctum::actingAs($this->caregiver);
        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->tasksPath($task['id']).'/start')
            ->assertOk();
        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->tasksPath($task['id']).'/complete', ['outcome' => 'done'])
            ->assertOk();

        Sanctum::actingAs($this->owner);
        $this->withHeaders(['If-Match' => '3'])
            ->postJson($this->tasksPath($task['id']).'/reject', ['reason' => 'Photo is unreadable'])
            ->assertOk()
            ->assertJsonPath('data.verification', 'rejected');

        $reason = 'Evidence was incomplete';
        $this->withHeaders(['If-Match' => '4'])
            ->postJson($this->tasksPath($task['id']).'/reopen', ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->useWorkspace();
        $reopen = TaskEvent::query()
            ->where('task_id', $task['id'])
            ->where('event', 'reopened')
            ->sole();

        $this->assertSame($reason, $reopen->reason);
        $this->assertSame('completed', $reopen->from_status);
        $this->assertSame('in_progress', $reopen->to_status);
    }

    public function test_ct_t08_a_second_primary_assignment_is_409_not_500(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();
        $this->assign($task['id'], $this->caregiver->id, '1');

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->tasksPath($task['id']).'/assign', [
                'user_id' => $this->verifier->id,
                'role' => 'primary',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'domain_rule');

        $this->useWorkspace();
        $this->assertSame(
            1,
            TaskAssignment::query()
                ->where('task_id', $task['id'])
                ->where('role', 'primary')
                ->whereNull('released_at')
                ->count(),
        );
    }

    public function test_ct_t09_released_primary_allows_a_new_primary(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();
        $this->assign($task['id'], $this->caregiver->id, '1');

        $this->useWorkspace();
        TaskAssignment::query()
            ->where('task_id', $task['id'])
            ->where('role', 'primary')
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->tasksPath($task['id']).'/assign', [
                'user_id' => $this->verifier->id,
                'role' => 'primary',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned');

        $this->useWorkspace();
        $primary = TaskAssignment::query()
            ->where('task_id', $task['id'])
            ->where('role', 'primary')
            ->whereNull('released_at')
            ->sole();

        $this->assertSame($this->verifier->id, $primary->user_id);
    }

    public function test_ct_t10_task_events_is_append_only(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();

        $this->useWorkspace();
        $eventId = (string) TaskEvent::query()->where('task_id', $task['id'])->value('id');

        try {
            DB::transaction(function () use ($eventId): void {
                DB::table('task_events')->where('id', $eventId)->update(['event' => 'tampered']);
            });
            $this->fail('task_events accepted an UPDATE');
        } catch (QueryException $exception) {
            $this->assertSame('55000', (string) $exception->getCode());
        }

        try {
            DB::transaction(function () use ($eventId): void {
                DB::table('task_events')->where('id', $eventId)->delete();
            });
            $this->fail('task_events accepted a DELETE');
        } catch (QueryException $exception) {
            $this->assertSame('55000', (string) $exception->getCode());
        }

        $this->assertSame('created', TaskEvent::query()->whereKey($eventId)->value('event'));
    }

    public function test_ct_t11_idempotency_key_returns_the_original_task_with_200(): void
    {
        Sanctum::actingAs($this->owner);

        $headers = ['Idempotency-Key' => 'ct-t11-unique-key'];

        $first = $this->withHeaders($headers)
            ->postJson($this->tasksPath(), $this->payload())
            ->assertCreated()
            ->json('data');

        $second = $this->withHeaders($headers)
            ->postJson($this->tasksPath(), $this->payload())
            ->assertOk()
            ->json('data');

        $this->assertSame($first['id'], $second['id']);

        $this->useWorkspace();
        $this->assertSame(1, CareTask::query()->where('idempotency_key', 'ct-t11-unique-key')->count());
    }

    public function test_ct_t12_a_task_from_another_workspace_is_404(): void
    {
        $foreign = $this->makeTask([], $this->otherWorkspaceProfile);

        Sanctum::actingAs($this->owner);

        $this->getJson($this->tasksPath($foreign->id))
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');
    }

    public function test_ct_t13_assignee_without_a_profile_grant_gets_the_safe_card(): void
    {
        $task = $this->makeTask([
            'profile_id' => $this->dependentProfile->id,
            'description' => 'SECRET-DESCRIPTION-MARKER',
        ], $this->dependentProfile);

        $this->makePrimaryAssignment($task, $this->caregiver);

        $this->useWorkspace();
        TaskEvidence::query()->create([
            'tenant_id' => $task->tenant_id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'type' => 'note',
            'note' => 'SECRET-EVIDENCE-NOTE',
            'created_by' => $this->caregiver->id,
        ]);

        Sanctum::actingAs($this->caregiver);

        $card = $this->getJson($this->tasksPath($task->id))
            ->assertOk()
            ->json('data');

        $this->assertSame('Directly created task', $card['title']);
        $this->assertSame('Dependent Person', $card['profile_display_name']);
        $this->assertSame($task->version, $card['version']);
        $this->assertArrayNotHasKey('description', $card);
        $this->assertArrayNotHasKey('verification', $card);
        $this->assertArrayNotHasKey('evidence', $card);

        $list = $this->getJson($this->tasksPath())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('description', $list[0]);
        $this->assertArrayNotHasKey('evidence', $list[0]);
    }

    public function test_ct_t14_a_user_with_a_tasks_grant_gets_the_full_dto(): void
    {
        $task = $this->makeTask([
            'profile_id' => $this->dependentProfile->id,
            'description' => 'FULL-DESCRIPTION-VISIBLE',
        ], $this->dependentProfile);

        $this->makePrimaryAssignment($task, $this->caregiver);
        $this->grant($this->caregiver, ['tasks'], ['read'], $this->dependentProfile);

        Sanctum::actingAs($this->caregiver);

        $this->getJson($this->tasksPath($task->id))
            ->assertOk()
            ->assertJsonPath('data.description', 'FULL-DESCRIPTION-VISIBLE')
            ->assertJsonPath('data.profile_display_name', 'Dependent Person');
    }

    public function test_ct_t15_every_transition_writes_one_task_event_and_one_audit_event(): void
    {
        Sanctum::actingAs($this->owner);

        $events = DB::table('task_events')->count();
        // P1.6b: a dispatched notification writes its own `notification.dispatch`
        // audit row. This test covers the transition's *domain* audit row, so
        // the notification fan-out is counted separately.
        $audits = DB::table('audit_events')
            ->where('action', '!=', 'notification.dispatch')
            ->count();

        $task = $this->createTaskViaApi();
        $this->assertHistoryIncremented($events, $audits, 'create');
        $events++;
        $audits++;

        $this->assign($task['id'], $this->caregiver->id, '1');
        $this->assertHistoryIncremented($events, $audits, 'assign');
        $events++;
        $audits++;

        Sanctum::actingAs($this->caregiver);
        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->tasksPath($task['id']).'/accept')
            ->assertOk();
        $this->assertHistoryIncremented($events, $audits, 'accept');
        $events++;
        $audits++;

        $this->withHeaders(['If-Match' => '3'])
            ->postJson($this->tasksPath($task['id']).'/start')
            ->assertOk();
        $this->assertHistoryIncremented($events, $audits, 'start');
        $events++;
        $audits++;

        $this->withHeaders(['If-Match' => '4'])
            ->postJson($this->tasksPath($task['id']).'/block', [
                'blocked_reason' => 'Waiting for the oxygen supplier',
                'review_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked');
        $this->assertHistoryIncremented($events, $audits, 'block');
        $events++;
        $audits++;

        // CT-005: blocked work is reviewed; `start` is the unblock path.
        $this->withHeaders(['If-Match' => '5'])
            ->postJson($this->tasksPath($task['id']).'/start')
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertHistoryIncremented($events, $audits, 'unblock');
        $events++;
        $audits++;

        $this->withHeaders(['If-Match' => '6'])
            ->postJson($this->tasksPath($task['id']).'/complete', ['outcome' => 'done'])
            ->assertOk();
        $this->assertHistoryIncremented($events, $audits, 'complete');

        $this->useWorkspace();
        $this->assertSame(7, TaskEvent::query()->where('task_id', $task['id'])->count());
        $this->assertSame(
            7,
            DB::table('audit_events')
                ->where('target_type', 'care_task')
                ->where('target_id', $task['id'])
                ->where('action', '!=', 'notification.dispatch')
                ->count(),
            'Each transition writes one domain audit; the notification fan-out is a separate audit row.',
        );
    }

    public function test_ct_t16_a_stale_if_match_is_412_and_changes_nothing(): void
    {
        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();

        $this->withHeaders(['If-Match' => '99'])
            ->postJson($this->tasksPath($task['id']).'/assign', [
                'user_id' => $this->caregiver->id,
            ])
            ->assertStatus(412)
            ->assertJsonPath('code', 'version_conflict');

        $this->useWorkspace();

        $stored = CareTask::query()->findOrFail($task['id']);
        $this->assertSame(CareTask::STATUS_OPEN, $stored->status);
        $this->assertSame(1, $stored->version);
        $this->assertSame(0, TaskAssignment::query()->where('task_id', $task['id'])->count());
    }

    private function assign(string $taskId, int $userId, string $version): void
    {
        $this->withHeaders(['If-Match' => $version])
            ->postJson($this->tasksPath($taskId).'/assign', [
                'user_id' => $userId,
                'role' => 'primary',
            ])
            ->assertOk();
    }

    private function assertHistoryIncremented(int $eventsBefore, int $auditsBefore, string $step): void
    {
        // ResolveWorkspaceContext clears the transaction-local RLS context in
        // its `finally`; re-establish it before counting directly.
        $this->useWorkspace();

        $this->assertSame(
            $eventsBefore + 1,
            DB::table('task_events')->count(),
            "{$step} must write exactly one task event",
        );
        $this->assertSame(
            $auditsBefore + 1,
            DB::table('audit_events')
                ->where('action', '!=', 'notification.dispatch')
                ->count(),
            "{$step} must write exactly one business audit event",
        );
    }
}
