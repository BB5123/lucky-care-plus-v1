<?php

namespace Tests\Feature\CareTask;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\CareCrm\Application\CareTaskEscalator;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\EscalationPolicy;
use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\Notifications\Models\CommunicationPreference;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * P2.2 acceptance suite — ESC-T01 ... ESC-T12.
 *
 * The clock is pinned so "31 minutes overdue" is deterministic. Each test
 * builds its own policy rows; nothing seeds a ladder by default (a workspace
 * with no policies escalates nothing).
 */
class EscalationTest extends CareTaskTestCase
{
    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-02 12:00:00', 'UTC'));

        $this->admin = User::factory()->create();
        $this->viewer = User::factory()->create();

        $this->addMember($this->workspace, $this->admin, 'workspace_admin');
        $this->addMember($this->workspace, $this->viewer, 'viewer');

        $this->useWorkspace();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -- ESC-T01 ------------------------------------------------------------

    public function test_esc_t01_a_task_31_minutes_overdue_climbs_to_level_1(): void
    {
        $this->makePolicy(['level' => 1, 'after_minutes' => 30]);
        $task = $this->overdueTask(31);

        $summary = $this->escalate();

        $this->assertSame(1, $summary['escalated']);

        $task->refresh();
        $this->assertSame(1, $task->escalation_level);

        $this->useWorkspace();

        $event = TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event', 'escalated')
            ->sole();

        $this->assertSame(0, $event->metadata['from']);
        $this->assertSame(1, $event->metadata['to']);

        $this->assertSame(1, AuditEvent::query()
            ->where('target_type', 'care_task')
            ->where('target_id', $task->id)
            ->where('action', 'care_task.escalate')
            ->count());
    }

    // -- ESC-T02 ------------------------------------------------------------

    public function test_esc_t02_running_the_ladder_again_does_not_repeat_a_level(): void
    {
        $this->makePolicy(['level' => 1, 'after_minutes' => 30]);
        $task = $this->overdueTask(31);

        $this->escalate();
        $second = $this->escalate();

        $this->assertSame(0, $second['escalated']);

        $task->refresh();
        $this->assertSame(1, $task->escalation_level);

        $this->useWorkspace();

        $this->assertSame(1, TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event', 'escalated')
            ->count());
    }

    // -- ESC-T03 ------------------------------------------------------------

    public function test_esc_t03_three_hours_overdue_jumps_straight_to_level_2(): void
    {
        $this->makePolicy(['level' => 1, 'after_minutes' => 30]);
        $this->makePolicy(['level' => 2, 'after_minutes' => 120]);
        $this->makePolicy(['level' => 3, 'after_minutes' => 360]);

        $task = $this->overdueTask(180);

        $summary = $this->escalate();

        $this->assertSame(1, $summary['escalated']);

        $task->refresh();
        $this->assertSame(2, $task->escalation_level);

        $this->useWorkspace();

        $events = TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event', 'escalated')
            ->get();

        $this->assertCount(1, $events, 'A single run must not fire one event per rung.');
        $this->assertSame(0, $events->first()->metadata['from']);
        $this->assertSame(2, $events->first()->metadata['to']);
    }

    // -- ESC-T04 ------------------------------------------------------------

    public function test_esc_t04_without_policies_nothing_escalates_or_is_written(): void
    {
        $task = $this->overdueTask(180);

        $this->useWorkspace();

        $eventCount = TaskEvent::query()->count();
        $auditCount = AuditEvent::query()->count();

        $summary = $this->escalate();

        $this->assertSame(0, $summary['escalated']);
        $this->assertSame(0, $summary['scanned']);

        $task->refresh();
        $this->assertSame(0, $task->escalation_level);

        $this->useWorkspace();
        $this->assertSame($eventCount, TaskEvent::query()->count());
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertCount(0, $this->deliveries());
    }

    // -- ESC-T05 ------------------------------------------------------------

    public function test_esc_t05_terminal_tasks_and_tasks_without_due_at_are_never_escalated(): void
    {
        $this->makePolicy(['level' => 1, 'after_minutes' => 30]);

        $completed = $this->overdueTask(120, [
            'status' => CareTask::STATUS_COMPLETED,
            'completed_at' => now()->subMinute(),
        ]);

        $cancelled = $this->overdueTask(120, [
            'status' => CareTask::STATUS_CANCELLED,
        ]);

        $noDueAt = $this->makeTask([
            'due_at' => null,
            'status' => CareTask::STATUS_OPEN,
        ]);

        $summary = $this->escalate();

        $this->assertSame(0, $summary['escalated']);

        foreach ([$completed, $cancelled, $noDueAt] as $task) {
            $task->refresh();
            $this->assertSame(0, $task->escalation_level, "Task {$task->id} must not escalate.");
        }

        $this->useWorkspace();

        $this->assertSame(0, TaskEvent::query()->where('event', 'escalated')->count());
    }

    // -- ESC-T06 ------------------------------------------------------------

    public function test_esc_t06_a_blocked_task_with_a_future_review_is_skipped(): void
    {
        $this->makePolicy(['level' => 1, 'after_minutes' => 30]);

        $task = $this->overdueTask(120, [
            'status' => CareTask::STATUS_BLOCKED,
            'blocked_reason' => 'Waiting for the clinic to call back',
            'review_at' => now()->addMinutes(30),
        ]);

        $summary = $this->escalate();

        $this->assertSame(0, $summary['escalated']);

        $task->refresh();
        $this->assertSame(0, $task->escalation_level);
    }

    // -- ESC-T07 ------------------------------------------------------------

    public function test_esc_t07_reassign_to_admin_moves_the_primary_and_records_history(): void
    {
        $this->makePolicy([
            'level' => 1,
            'after_minutes' => 30,
            'notify_assignee' => false,
            'notify_roles' => ['workspace_admin'],
            'action' => EscalationPolicy::ACTION_REASSIGN_TO_ADMIN,
        ]);

        $task = $this->overdueTask(31);
        $this->makePrimaryAssignment($task, $this->caregiver);

        $summary = $this->escalate();

        $this->assertSame(1, $summary['escalated']);
        $this->assertSame(1, $summary['reassigned']);

        $task->refresh();
        $this->assertSame(1, $task->escalation_level);
        $this->assertSame(CareTask::STATUS_ASSIGNED, $task->status);

        $this->useWorkspace();

        $activePrimary = TaskAssignment::query()
            ->where('task_id', $task->id)
            ->where('role', TaskAssignment::ROLE_PRIMARY)
            ->whereNull('released_at')
            ->sole();

        $this->assertSame($this->admin->id, $activePrimary->user_id);

        $previous = TaskAssignment::query()
            ->where('task_id', $task->id)
            ->where('user_id', $this->caregiver->id)
            ->sole();

        $this->assertNotNull($previous->released_at, 'CT-007: the previous primary is released, not deleted.');

        $event = TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event', 'escalated')
            ->sole();

        $this->assertSame(0, $event->metadata['from']);
        $this->assertSame(1, $event->metadata['to']);
        $this->assertSame($this->admin->id, $event->metadata['reassigned_user_id']);

        $this->assertSame(1, AuditEvent::query()
            ->where('target_type', 'care_task')
            ->where('target_id', $task->id)
            ->where('action', 'care_task.escalate')
            ->count());

        $this->assertSame(1, AuditEvent::query()
            ->where('target_type', 'care_task')
            ->where('target_id', $task->id)
            ->where('action', 'care_task.assign')
            ->count());
    }

    // -- ESC-T08 ------------------------------------------------------------

    public function test_esc_t08_the_notification_carries_only_level_and_due_at(): void
    {
        $this->makePolicy([
            'level' => 1,
            'after_minutes' => 30,
            'notify_assignee' => true,
            'notify_roles' => [],
        ]);

        $task = $this->overdueTask(31, [
            'title' => 'Secret health task title',
        ]);

        $this->makePrimaryAssignment($task, $this->caregiver);
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);

        $this->escalate();

        $delivery = $this->deliveries()
            ->where('recipient_user_id', $this->caregiver->id)
            ->where('template_key', 'task.escalated')
            ->sole();

        $params = $delivery->params;

        $keys = array_keys($params);
        sort($keys);

        $this->assertSame(['due_at', 'escalation_level'], $keys);
        $this->assertSame(1, $params['escalation_level']);
        $this->assertSame($task->due_at->toIso8601String(), $params['due_at']);
        $this->assertArrayNotHasKey('title', $params);
        $this->assertArrayNotHasKey('name', $params);
        $this->assertStringNotContainsString('Secret health task title', json_encode($params));
    }

    // -- ESC-T09 ------------------------------------------------------------

    public function test_esc_t09_dry_run_writes_nothing(): void
    {
        $this->makePolicy(['level' => 1, 'after_minutes' => 30]);
        $task = $this->overdueTask(31);
        $assignment = $this->makePrimaryAssignment($task, $this->caregiver);

        $this->useWorkspace();
        $eventCount = TaskEvent::query()->count();
        $auditCount = AuditEvent::query()->count();

        Artisan::call('tasks:escalate', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Dry run', $output);

        $task->refresh();
        $this->assertSame(0, $task->escalation_level);

        $this->useWorkspace();
        $this->assertSame($eventCount, TaskEvent::query()->count());
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertCount(0, $this->deliveries());

        $assignment->refresh();
        $this->assertNull($assignment->released_at);
    }

    // -- ESC-T10 ------------------------------------------------------------

    public function test_esc_t10_put_rejects_bad_ladders_and_unknown_roles_with_422(): void
    {
        Sanctum::actingAs($this->owner);

        $path = $this->escalationPath();

        // Duplicate level.
        $this->putJson($path, ['policies' => [
            $this->policyPayload(['level' => 1]),
            $this->policyPayload(['level' => 1, 'after_minutes' => 120]),
        ]])->assertStatus(422);

        // A hole in the ladder.
        $this->putJson($path, ['policies' => [
            $this->policyPayload(['level' => 1]),
            $this->policyPayload(['level' => 3, 'after_minutes' => 360]),
        ]])->assertStatus(422);

        // Delays that do not increase with the level.
        $this->putJson($path, ['policies' => [
            $this->policyPayload(['level' => 1, 'after_minutes' => 120]),
            $this->policyPayload(['level' => 2, 'after_minutes' => 30]),
        ]])->assertStatus(422);

        // Unknown membership role.
        $this->putJson($path, ['policies' => [
            $this->policyPayload(['notify_roles' => ['not_a_role']]),
        ]])->assertStatus(422);

        $this->useWorkspace();
        $this->assertSame(0, EscalationPolicy::query()->count(), 'A rejected PUT must not partially replace the ladder.');
    }

    // -- ESC-T11 ------------------------------------------------------------

    public function test_esc_t11_get_needs_workspace_read_and_put_needs_workspace_update(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->getJson($this->escalationPath())
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->putJson($this->escalationPath(), ['policies' => [$this->policyPayload()]])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs($this->owner);

        $this->putJson($this->escalationPath(), ['policies' => [$this->policyPayload()]])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // The workspace ladder is isolated: the same owner is a member of the
        // second workspace, so a missing scope filter would leak this row.
        $this->getJson("/api/v1/workspaces/{$this->otherWorkspace->id}/escalation-policies")
            ->assertOk()
            ->assertJsonPath('data', []);

        // POST /defaults is the only supported way to materialise the
        // config suggestion; it replaces the ladder atomically.
        $this->postJson($this->escalationPath().'/defaults')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.level', 1)
            ->assertJsonPath('data.1.level', 2)
            ->assertJsonPath('data.2.action', 'reassign_to_admin');
    }

    // -- ESC-T12 ------------------------------------------------------------

    public function test_esc_t12_the_command_is_scheduled_every_fifteen_minutes(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('tasks:escalate', $output);
        $this->assertStringContainsString('*/15', $output);
    }

    // -- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePolicy(array $attributes = []): EscalationPolicy
    {
        $this->useWorkspace();

        return EscalationPolicy::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'level' => 1,
            'after_minutes' => 30,
            'notify_assignee' => true,
            'notify_roles' => [],
            'action' => EscalationPolicy::ACTION_NOTIFY,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function overdueTask(int $minutes, array $attributes = []): CareTask
    {
        return $this->makeTask(array_merge([
            'due_at' => now()->subMinutes($minutes),
            'status' => CareTask::STATUS_OPEN,
            'escalation_level' => 0,
        ], $attributes));
    }

    /**
     * @return array{scanned: int, escalated: int, skipped: int, reassigned: int}
     */
    private function escalate(): array
    {
        return app(CareTaskEscalator::class)->run();
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
            'action' => EscalationPolicy::ACTION_NOTIFY,
            'is_active' => true,
        ], $attributes);
    }

    /**
     * @param  array<string, bool>  $channels
     */
    private function setChannels(User $user, array $channels): void
    {
        $this->useWorkspace();

        CommunicationPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'locale' => 'th',
                'channels' => $channels,
                'quiet_hours' => null,
                'digest' => 'immediate',
            ],
        );
    }

    /**
     * Deliveries are owner-only under RLS; a scheduled job reads them through
     * the transaction-local system window, so the test does the same.
     *
     * @return Collection<int, NotificationDelivery>
     */
    private function deliveries(): Collection
    {
        return DB::transaction(function (): Collection {
            DB::selectOne('select set_config(?, ?, true)', ['app.notification_system', 'on']);

            try {
                return NotificationDelivery::query()->get();
            } finally {
                DB::selectOne('select set_config(?, ?, true)', ['app.notification_system', '']);
            }
        });
    }
}
