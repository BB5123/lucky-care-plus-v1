<?php

namespace Tests\Feature\CareTask;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\CareCrm\Application\CareTaskMaterializer;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\CareTaskTemplate;
use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\CareCrm\Models\TaskEvent;
use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsWorkspaceState;

/**
 * P2.1 acceptance suite — REC-T01 ... REC-T13.
 *
 * The clock is pinned to Monday 2026-03-02 00:00 Asia/Bangkok so occurrences
 * are deterministic and every slot for "today" is still in the future.
 */
class RecurringTaskTest extends CareTaskTestCase
{
    use SetsWorkspaceState;

    private const TZ = 'Asia/Bangkok';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-02 00:00:00', self::TZ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -- REC-T01 ------------------------------------------------------------

    public function test_rec_t01_a_daily_template_materialises_one_occurrence_per_day(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-08',
            'times_of_day' => ['08:00'],
        ]);

        $summary = $this->materialise(7);

        $this->assertSame(1, $summary['templates']);
        $this->assertSame(7, $summary['created']);

        $tasks = $this->tasksFor($template['id']);

        $this->assertCount(7, $tasks);
        $this->assertSame([
            '2026-03-02',
            '2026-03-03',
            '2026-03-04',
            '2026-03-05',
            '2026-03-06',
            '2026-03-07',
            '2026-03-08',
        ], $tasks->map(fn (CareTask $task): string => $task->scheduled_for->copy()->setTimezone(self::TZ)->format('Y-m-d'))->all());
    }

    // -- REC-T02 ------------------------------------------------------------

    public function test_rec_t02_running_the_materialiser_twice_creates_no_new_rows(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-04',
            'times_of_day' => ['08:00'],
        ]);

        $first = $this->materialise(3);
        $second = $this->materialise(3);

        $this->assertSame(3, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertCount(3, $this->tasksFor($template['id']));
    }

    // -- REC-T03 ------------------------------------------------------------

    public function test_rec_t03_a_weekly_template_only_lands_on_its_weekdays(): void
    {
        $template = $this->createTemplate([
            'frequency' => 'weekly',
            'days_of_week' => [1, 3, 5],
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-08',
            'times_of_day' => ['08:00'],
        ]);

        $this->materialise(7);

        $tasks = $this->tasksFor($template['id']);

        $this->assertCount(3, $tasks);
        $this->assertSame([1, 3, 5], $tasks
            ->map(fn (CareTask $task): int => (int) $task->scheduled_for->copy()->setTimezone(self::TZ)->isoWeekday())
            ->sort()
            ->values()
            ->all());
    }

    // -- REC-T04 ------------------------------------------------------------

    public function test_rec_t04_times_of_day_produces_one_occurrence_per_slot(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-02',
            'times_of_day' => ['08:00', '20:00'],
        ]);

        $summary = $this->materialise(0);

        $this->assertSame(2, $summary['created']);

        $times = $this->tasksFor($template['id'])
            ->map(fn (CareTask $task): string => $task->scheduled_for->copy()->setTimezone(self::TZ)->format('H:i'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['08:00', '20:00'], $times);
    }

    // -- REC-T05 ------------------------------------------------------------

    public function test_rec_t05_skip_suppresses_an_occurrence_and_reschedule_moves_it(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-04',
            'times_of_day' => ['08:00'],
        ]);

        Sanctum::actingAs($this->owner);

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->templatePath($template['id']).'/exceptions', [
                'occurrence_date' => '2026-03-03',
                'action' => 'skip',
            ])
            ->assertCreated();

        $this->withHeaders(['If-Match' => '2'])
            ->postJson($this->templatePath($template['id']).'/exceptions', [
                'occurrence_date' => '2026-03-04',
                'action' => 'reschedule',
                'rescheduled_to' => '2026-03-04T10:30:00+07:00',
            ])
            ->assertCreated();

        $this->materialise(3);

        $tasks = $this->tasksFor($template['id']);

        $this->assertCount(2, $tasks);
        $this->assertSame(
            ['2026-03-02 08:00', '2026-03-04 10:30'],
            $tasks
                ->map(fn (CareTask $task): string => $task->scheduled_for->copy()->setTimezone(self::TZ)->format('Y-m-d H:i'))
                ->sort()
                ->values()
                ->all(),
        );
    }

    // -- REC-T06 ------------------------------------------------------------

    public function test_rec_t06_deactivating_stops_future_occurrences_but_keeps_existing_ones(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-08',
            'times_of_day' => ['08:00'],
        ]);

        $this->materialise(2);
        $this->assertCount(3, $this->tasksFor($template['id']));

        Sanctum::actingAs($this->owner);

        $this->withHeaders(['If-Match' => '1'])
            ->postJson($this->templatePath($template['id']).'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.version', 2);

        $summary = $this->materialise(8);

        $this->assertSame(0, $summary['created']);
        $this->assertCount(3, $this->tasksFor($template['id']));
    }

    // -- REC-T07 ------------------------------------------------------------

    public function test_rec_t07_a_template_that_ends_mid_window_stops_at_ends_on(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-04',
            'times_of_day' => ['08:00'],
        ]);

        $this->materialise(14);

        $tasks = $this->tasksFor($template['id']);

        $this->assertCount(3, $tasks);
        $this->assertSame(
            '2026-03-04',
            $tasks->max(fn (CareTask $task): string => $task->scheduled_for->copy()->setTimezone(self::TZ)->format('Y-m-d')),
        );
    }

    // -- REC-T08 ------------------------------------------------------------

    public function test_rec_t08_rotating_assigns_caregivers_round_robin_and_is_stable_across_runs(): void
    {
        $template = $this->createTemplate([
            'assignee_strategy' => 'rotating',
            'assigned_user_id' => null,
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-04',
            'times_of_day' => ['08:00'],
        ]);

        $this->materialise(3);

        // CareTaskTestCase joins owner (profile_owner), then caregiver, helper
        // and verifier (all active `caregiver` role), ordered by membership id.
        $expected = [$this->caregiver->id, $this->helper->id, $this->verifier->id];
        $actual = $this->tasksFor($template['id'])
            ->map(fn (CareTask $task): int => (int) TaskAssignment::query()
                ->where('task_id', $task->id)
                ->where('role', TaskAssignment::ROLE_PRIMARY)
                ->whereNull('released_at')
                ->value('user_id'))
            ->all();

        $this->assertSame($expected, $actual);

        $second = $this->materialise(3);
        $this->assertSame(0, $second['created']);

        $after = $this->tasksFor($template['id'])
            ->map(fn (CareTask $task): int => (int) TaskAssignment::query()
                ->where('task_id', $task->id)
                ->where('role', TaskAssignment::ROLE_PRIMARY)
                ->whereNull('released_at')
                ->value('user_id'))
            ->all();

        $this->assertSame($expected, $after);
    }

    // -- REC-T09 ------------------------------------------------------------

    public function test_rec_t09_occurrences_created_through_the_service_have_events_and_audit_rows(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-02',
            'times_of_day' => ['08:00'],
        ]);

        $this->materialise(0);

        $task = $this->tasksFor($template['id'])->sole();

        $this->useWorkspace();
        $this->assertSame(1, TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event', 'created')
            ->count());

        $this->assertSame(1, AuditEvent::query()
            ->where('target_type', 'care_task')
            ->where('target_id', $task->id)
            ->where('action', 'care_task.create')
            ->count());
    }

    // -- REC-T10 ------------------------------------------------------------

    public function test_rec_t10_occurrences_in_the_past_are_never_created(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2020-01-01',
            'ends_on' => null,
            'times_of_day' => ['08:00'],
        ]);

        $this->materialise(1);

        $tasks = $this->tasksFor($template['id']);
        $todayStart = Carbon::parse('2026-03-02 00:00:00', self::TZ)->utc();

        $this->assertNotEmpty($tasks);
        $this->assertTrue($tasks->every(
            fn (CareTask $task): bool => $task->scheduled_for->greaterThanOrEqualTo($todayStart),
        ));
    }

    // -- REC-T11 ------------------------------------------------------------

    public function test_rec_t11_a_fixed_template_without_an_assigned_user_is_422(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->templatePath(), $this->templatePayload([
            'assignee_strategy' => 'fixed',
            'assigned_user_id' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_user_id');
    }

    // -- REC-T12 ------------------------------------------------------------

    public function test_rec_t12_dry_run_writes_nothing_but_reports_the_counts(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-04',
            'times_of_day' => ['08:00'],
        ]);

        Artisan::call('tasks:materialise', ['--days' => 3, '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString('would be created', $output);
        $this->assertCount(0, $this->tasksFor($template['id']));

        Artisan::call('tasks:materialise', ['--days' => 3]);
        $this->assertCount(3, $this->tasksFor($template['id']));
    }

    // -- REC-T14 ------------------------------------------------------------

    public function test_rec_t14_a_read_only_workspace_is_not_materialised(): void
    {
        $template = $this->createTemplate([
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-04',
            'times_of_day' => ['08:00'],
        ]);

        $this->setWorkspaceState(RetentionState::ReadOnly);

        $this->materialise(3);

        $this->assertCount(0, $this->tasksFor($template['id']));

        // The same template materialises once the account is active again, so
        // the filter - not a broken fixture - is what suppressed it.
        $this->setWorkspaceState(RetentionState::Active);

        $this->materialise(3);

        $this->assertCount(3, $this->tasksFor($template['id']));
    }

    // -- REC-T13 ------------------------------------------------------------

    public function test_rec_t13_cross_workspace_template_is_404_and_viewer_cannot_create(): void
    {
        $foreign = $this->makeForeignTemplate();

        Sanctum::actingAs($this->owner);

        $this->getJson($this->templatePath($foreign->id))
            ->assertStatus(404)
            ->assertJsonPath('code', 'out_of_scope');

        $viewer = User::factory()->create();
        $this->addMember($this->workspace, $viewer, 'viewer');

        Sanctum::actingAs($viewer);

        $this->postJson($this->templatePath(), $this->templatePayload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    // -- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function createTemplate(array $attributes = []): array
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson($this->templatePath(), $this->templatePayload($attributes))
            ->assertCreated()
            ->json('data');
    }

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
            'timezone' => self::TZ,
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

    /**
     * @return array{created: int, skipped: int, templates: int}
     */
    private function materialise(int $days, ?Workspace $workspace = null): array
    {
        return app(CareTaskMaterializer::class)->materialise($days, $workspace ?? $this->workspace);
    }

    /**
     * @return Collection<int, CareTask>
     */
    private function tasksFor(string $templateId)
    {
        $this->useWorkspace();

        return CareTask::query()
            ->where('template_id', $templateId)
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();
    }

    private function makeForeignTemplate(): CareTaskTemplate
    {
        $this->useWorkspace($this->otherWorkspace);

        $template = CareTaskTemplate::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->otherWorkspace->id,
            'profile_id' => $this->otherWorkspaceProfile->id,
            'created_by' => $this->owner->id,
            'title' => 'Foreign schedule',
            'description' => null,
            'tier' => CareTask::TIER_ROUTINE,
            'type' => 'DAILY_CARE',
            'priority' => 'normal',
            'frequency' => 'daily',
            'interval' => 1,
            'days_of_week' => null,
            'times_of_day' => ['08:00'],
            'timezone' => self::TZ,
            'starts_on' => '2026-03-02',
            'ends_on' => null,
            'evidence_policy' => 'none',
            'verification_required' => false,
            'assignee_strategy' => 'unassigned',
            'assigned_user_id' => null,
            'is_active' => true,
            'version' => 1,
        ]);

        $this->useWorkspace();

        return $template;
    }
}
