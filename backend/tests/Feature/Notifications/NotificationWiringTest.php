<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\CareCrm\Application\CareTaskTransitionService;
use App\Modules\CareCrm\Models\Appointment;
use App\Modules\CareCrm\Models\TaskAssignment;
use App\Modules\Notifications\Domain\UnknownNotificationTemplateException;
use App\Modules\Notifications\Jobs\DispatchNotificationJob;
use App\Modules\Notifications\Models\CommunicationPreference;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Retention\Domain\RetentionState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use RuntimeException;
use Tests\Concerns\SetsWorkspaceState;
use Tests\Feature\CareTask\CareTaskTestCase;
use Throwable;

/**
 * P1.6b acceptance suite — WIRE-T01 ... WIRE-T11.
 *
 * `QUEUE_CONNECTION=sync` (phpunit.xml) makes the queued job run inline, which
 * is exactly what lets these tests observe the delivery rows a real queue
 * worker would produce. The HTTP cases go through the full stack so the
 * event → listener → job → dispatcher chain is exercised, not mocked.
 */
class NotificationWiringTest extends CareTaskTestCase
{
    use SetsWorkspaceState;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -- WIRE-T01 -----------------------------------------------------------

    public function test_wire_t01_assigning_a_primary_assignee_notifies_that_assignee(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();
        $this->assignPrimary($task['id'], $this->caregiver->id, 1);

        $deliveries = $this->deliveriesFor($this->caregiver, 'task.assigned');

        $this->assertCount(1, $deliveries);
        $this->assertSame($this->caregiver->id, $deliveries->first()->recipient_user_id);
        $this->assertSame(NotificationDelivery::CHANNEL_IN_APP, $deliveries->first()->channel);
        $this->assertSame($task['id'], $deliveries->first()->subject_id);
        $this->assertSame('care_task', $deliveries->first()->subject_type);
        $this->assertSame($task['id'], $deliveries->first()->params['task_id'] ?? null);

        $this->assertCount(0, $this->deliveriesFor($this->owner, 'task.assigned'));
        $this->assertCount(0, $this->deliveriesFor($this->helper, 'task.assigned'));
    }

    // -- WIRE-T02 -----------------------------------------------------------

    public function test_wire_t02_reassignment_notifies_the_new_assignee_only(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);
        $this->setChannels($this->verifier, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();
        $this->assignPrimary($task['id'], $this->caregiver->id, 1);

        $caregiverBefore = $this->deliveriesFor($this->caregiver, 'task.assigned')->count();
        $this->assertSame(1, $caregiverBefore);

        // Reassignment = release the old primary, then assign a new one.
        $this->useWorkspace();
        TaskAssignment::query()
            ->where('task_id', $task['id'])
            ->where('role', TaskAssignment::ROLE_PRIMARY)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        $this->assignPrimary($task['id'], $this->verifier->id, 2);

        $this->assertSame(
            $caregiverBefore,
            $this->deliveriesFor($this->caregiver, 'task.assigned')->count(),
            'The previous assignee must not be notified again.',
        );
        $this->assertCount(1, $this->deliveriesFor($this->verifier, 'task.assigned'));
    }

    // -- WIRE-T03 -----------------------------------------------------------

    public function test_wire_t03_disabled_in_app_channel_produces_no_in_app_row_but_other_channels_work(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => false, 'push' => true, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();
        $this->assignPrimary($task['id'], $this->caregiver->id, 1);

        $this->assertCount(0, $this->deliveriesFor($this->caregiver, 'task.assigned', 'in_app'));

        $push = $this->deliveriesFor($this->caregiver, 'task.assigned', 'push');
        $this->assertCount(1, $push);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $push->first()->status);
        $this->assertSame(NotificationDelivery::ERROR_NOT_CONFIGURED, $push->first()->error_code);
    }

    // -- WIRE-T04 -----------------------------------------------------------

    public function test_wire_t04_creating_an_appointment_notifies_the_profile_owner(): void
    {
        $this->setChannels($this->owner, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $appointment = $this->createAppointmentViaApi([
            'scheduled_at' => now()->addHours(3)->toIso8601String(),
        ]);

        $deliveries = $this->deliveriesFor($this->owner, 'appt.reminder');

        $this->assertCount(1, $deliveries);
        $this->assertSame(NotificationDelivery::CHANNEL_IN_APP, $deliveries->first()->channel);
        $this->assertSame($appointment['id'], $deliveries->first()->subject_id);
        $this->assertSame('appointment', $deliveries->first()->subject_type);
        $this->assertSame($appointment['id'], $deliveries->first()->params['appointment_id'] ?? null);
    }

    // -- WIRE-T05 -----------------------------------------------------------

    public function test_wire_t05_send_due_notifies_a_task_due_in_12h_and_is_idempotent(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'assignee_user_id' => $this->caregiver->id,
            'due_at' => now()->addHours(12)->toIso8601String(),
        ]);

        $this->artisan('notifications:send-due')->assertSuccessful();

        $this->assertCount(1, $this->deliveriesFor($this->caregiver, 'task.due_soon'));

        $this->artisan('notifications:send-due')->assertSuccessful();

        $deliveries = $this->deliveriesFor($this->caregiver, 'task.due_soon');
        $this->assertCount(1, $deliveries, 'A second run must not create a duplicate reminder.');
        $this->assertSame($task['id'], $deliveries->first()->subject_id);
    }

    // -- WIRE-T06 -----------------------------------------------------------

    public function test_wire_t06_send_due_ignores_a_task_due_in_48h(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $this->createTaskViaApi([
            'assignee_user_id' => $this->caregiver->id,
            'due_at' => now()->addHours(48)->toIso8601String(),
        ]);

        $this->artisan('notifications:send-due')->assertSuccessful();

        $this->assertCount(0, $this->deliveriesFor($this->caregiver, 'task.due_soon'));
    }

    // -- WIRE-T07 -----------------------------------------------------------

    public function test_wire_t07_dry_run_writes_nothing_but_reports_the_candidate(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'assignee_user_id' => $this->caregiver->id,
            'due_at' => now()->addHours(12)->toIso8601String(),
        ]);

        $before = $this->allDeliveries()->count();

        $this->artisan('notifications:send-due', ['--dry-run' => true])
            ->expectsOutputToContain($task['id'])
            ->assertSuccessful();

        $this->assertCount(0, $this->deliveriesFor($this->caregiver, 'task.due_soon'));
        $this->assertSame($before, $this->allDeliveries()->count(), 'A dry run must not write a row.');
    }

    // -- WIRE-T08 -----------------------------------------------------------

    public function test_wire_t08_appointment_reminders_are_idempotent_per_appointment(): void
    {
        $this->setChannels($this->owner, ['in_app' => true, 'push' => false, 'line' => false]);

        $appointment = $this->makeAppointment([
            'scheduled_at' => now()->addHours(12),
        ]);

        $this->artisan('notifications:send-appointment-reminders')->assertSuccessful();
        $this->artisan('notifications:send-appointment-reminders')->assertSuccessful();

        $deliveries = $this->deliveriesFor($this->owner, 'appt.reminder');

        $this->assertCount(1, $deliveries);
        $this->assertSame($appointment->id, $deliveries->first()->subject_id);
    }

    // -- WIRE-T09 -----------------------------------------------------------

    public function test_wire_t09_a_rolled_back_assignment_sends_no_notification(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi();

        $this->useWorkspace();

        try {
            DB::transaction(function () use ($task): void {
                app(CareTaskTransitionService::class)->apply(
                    $this->workspace,
                    $task['id'],
                    $this->owner,
                    'assign',
                    1,
                    ['user_id' => $this->caregiver->id, 'role' => TaskAssignment::ROLE_PRIMARY],
                );

                throw new RuntimeException('forced rollback after the assignment');
            });

            $this->fail('Expected the forced rollback to surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced rollback after the assignment', $exception->getMessage());
        }

        $this->assertCount(0, $this->deliveriesFor($this->caregiver, 'task.assigned'));
        $this->assertSame(
            0,
            $this->deliveriesFor($this->caregiver, 'task.assigned')->count(),
            'No delivery may survive a rolled-back assignment.',
        );
    }

    // -- WIRE-T10 -----------------------------------------------------------

    public function test_wire_t10_every_delivery_param_is_outside_the_dispatchers_phi_blacklist(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => true, 'line' => false]);
        $this->setChannels($this->owner, ['in_app' => true, 'push' => true, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'assignee_user_id' => $this->caregiver->id,
        ]);

        $this->createAppointmentViaApi([
            'scheduled_at' => now()->addHours(4)->toIso8601String(),
        ]);

        $deliveries = $this->allDeliveries();

        $this->assertGreaterThanOrEqual(2, $deliveries->count());

        $forbidden = (new ReflectionClass(NotificationDispatcher::class))
            ->getReflectionConstant('FORBIDDEN_PARAM_KEYS')
            ->getValue();

        $this->assertIsArray($forbidden);

        foreach ($deliveries as $delivery) {
            $params = is_array($delivery->params) ? $delivery->params : [];

            foreach (array_keys($params) as $key) {
                $this->assertNotContains(
                    strtolower((string) $key),
                    $forbidden,
                    "Delivery {$delivery->id} contains a PHI-shaped param key.",
                );
            }
        }

        // The assignment reminder must index the real task, never a title.
        $assignment = $this->deliveriesFor($this->caregiver, 'task.assigned')->first();
        $this->assertNotNull($assignment);
        $this->assertSame($task['id'], $assignment->params['task_id'] ?? null);
        $this->assertArrayNotHasKey('title', $assignment->params);
    }

    // -- WIRE-T11 -----------------------------------------------------------

    public function test_wire_t11_the_job_logs_and_rethrows_when_the_dispatcher_throws(): void
    {
        Log::spy();

        $job = new DispatchNotificationJob(
            tenantId: $this->tenant->id,
            workspaceId: $this->workspace->id,
            templateKey: 'task.not_a_real_template',
            recipientUserIds: [$this->caregiver->id],
            params: [],
            subjectType: 'care_task',
            subjectId: null,
        );

        $caught = null;

        try {
            $job->handle(app(NotificationDispatcher::class));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(UnknownNotificationTemplateException::class, $caught);
        $this->assertSame(3, $job->tries, 'Retry policy must stay 3 attempts.');
        $this->assertSame([10, 60], $job->backoff, 'Backoff must stay unchanged.');
        $this->assertSame(0, $this->allDeliveries()->count(), 'A failed dispatch writes no delivery row.');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'notification.dispatch.failed'
                    && ($context['template_key'] ?? null) === 'task.not_a_real_template'
                    && array_key_exists('error', $context)
                    && ! array_key_exists('params', $context)
                    && ! array_key_exists('recipient_user_ids', $context)
                    && ! array_key_exists('recipients', $context);
            })
            ->once();
    }

    // -- WIRE-T12 -----------------------------------------------------------

    public function test_wire_t12_due_soon_reminders_skip_a_read_only_workspace(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'assignee_user_id' => $this->caregiver->id,
            'due_at' => now()->addHours(12)->toIso8601String(),
        ]);

        $this->setWorkspaceState(RetentionState::ReadOnly);

        $this->artisan('notifications:send-due')->assertSuccessful();

        $this->assertCount(0, $this->deliveriesFor($this->caregiver, 'task.due_soon'));

        // Restoring the account makes the same reminder go out, so the state -
        // not a missing fixture - is what suppressed it.
        $this->setWorkspaceState(RetentionState::Active);

        $this->artisan('notifications:send-due')->assertSuccessful();

        $deliveries = $this->deliveriesFor($this->caregiver, 'task.due_soon');
        $this->assertCount(1, $deliveries);
        $this->assertSame($task['id'], $deliveries->first()->subject_id);
    }

    // -- WIRE-T13 -----------------------------------------------------------

    public function test_wire_t13_a_queued_notification_is_dropped_for_a_read_only_workspace(): void
    {
        $this->setChannels($this->caregiver, ['in_app' => true, 'push' => false, 'line' => false]);

        Sanctum::actingAs($this->owner);

        $task = $this->createTaskViaApi([
            'assignee_user_id' => $this->caregiver->id,
            'due_at' => now()->addHours(12)->toIso8601String(),
        ]);

        // The job was queued while the account was active, then the account
        // lapsed: delivery must be dropped, not retried.
        $job = new DispatchNotificationJob(
            tenantId: $this->tenant->id,
            workspaceId: $this->workspace->id,
            templateKey: 'task.due_soon',
            recipientUserIds: [$this->caregiver->id],
            params: [
                'task_id' => $task['id'],
                'due_at' => now()->addHours(12)->toIso8601String(),
            ],
            subjectType: 'care_task',
            subjectId: $task['id'],
        );

        $this->setWorkspaceState(RetentionState::ReadOnly);

        $job->handle(app(NotificationDispatcher::class));

        $this->assertCount(0, $this->deliveriesFor($this->caregiver, 'task.due_soon'));

        $this->setWorkspaceState(RetentionState::Active);

        $job->handle(app(NotificationDispatcher::class));

        $this->assertCount(1, $this->deliveriesFor($this->caregiver, 'task.due_soon'));
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * @param  array<string, bool>  $channels
     */
    private function setChannels(User $user, array $channels): void
    {
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

    private function assignPrimary(string $taskId, int $userId, int $version): void
    {
        $this->withHeaders(['If-Match' => (string) $version])
            ->postJson($this->tasksPath($taskId).'/assign', [
                'user_id' => $userId,
                'role' => TaskAssignment::ROLE_PRIMARY,
            ])
            ->assertOk();
    }

    private function appointmentsPath(): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/profiles/{$this->profile->id}/appointments";
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function createAppointmentViaApi(array $attributes = []): array
    {
        return $this->postJson($this->appointmentsPath(), array_merge([
            'title' => 'ตรวจติดตามอาการ',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ], $attributes))
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeAppointment(array $attributes = []): Appointment
    {
        $this->useWorkspace();

        return Appointment::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profile->id,
            'created_by' => $this->owner->id,
            'source' => 'manual',
            'title' => 'Direct appointment fixture',
            'scheduled_at' => now()->addHours(12),
            'status' => Appointment::STATUS_PLANNED,
            'prepared_documents' => [],
            'version' => 1,
        ], $attributes));
    }

    /**
     * Deliveries for one recipient, optionally narrowed by template/channel.
     * Read under the dispatcher's transaction-local system window because the
     * ledger's RLS policy is owner-only.
     */
    private function deliveriesFor(
        User $recipient,
        ?string $templateKey = null,
        ?string $channel = null,
    ): Collection {
        return $this->withSystemWindow(fn (): Collection => NotificationDelivery::query()
            ->where('recipient_user_id', $recipient->id)
            ->when($templateKey !== null, fn ($query) => $query->where('template_key', $templateKey))
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
            ->get());
    }

    private function allDeliveries(): Collection
    {
        return $this->withSystemWindow(fn (): Collection => NotificationDelivery::query()->get());
    }

    private function withSystemWindow(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            DB::selectOne('select set_config(?, ?, true)', ['app.notification_system', 'on']);

            try {
                return $callback();
            } finally {
                DB::selectOne('select set_config(?, ?, true)', ['app.notification_system', '']);
            }
        });
    }
}
