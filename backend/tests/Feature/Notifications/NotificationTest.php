<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\Notifications\Domain\NotificationPayloadException;
use App\Modules\Notifications\Domain\UnknownNotificationTemplateException;
use App\Modules\Notifications\Models\CommunicationPreference;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\NotificationDispatcher;
use App\Support\Database\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * P1.6 acceptance suite — NOTIF-T01 ... NOTIF-T12.
 *
 * The HTTP cases go through the real stack (`auth:sanctum`, `self.context`,
 * `authorize.permission`) because the self-membership decision and the
 * actor-scoped RLS/query layer are part of the contract, not controller
 * implementation detail. Dispatcher cases call the service directly to observe
 * row-level outcomes (including "no row was written").
 */
class NotificationTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private $tenant;

    private $workspace;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant('notif-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Notifications Workspace');

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        $this->addMember($this->workspace, $this->userA, 'caregiver');
        $this->addMember($this->workspace, $this->userB, 'viewer');

        $this->useWorkspace();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -- NOTIF-T01 ----------------------------------------------------------

    public function test_notif_t01_get_preferences_creates_defaults_on_first_read(): void
    {
        Sanctum::actingAs($this->userA);

        $this->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.locale', 'th')
            ->assertJsonPath('data.digest', 'immediate')
            ->assertJsonPath('data.channels.in_app', true)
            ->assertJsonPath('data.channels.push', true)
            ->assertJsonPath('data.channels.line', false)
            ->assertJsonPath('data.quiet_hours', null);

        $this->assertSame(
            1,
            CommunicationPreference::query()->where('user_id', $this->userA->id)->count(),
        );
    }

    // -- NOTIF-T02 ----------------------------------------------------------

    public function test_notif_t02_patch_updates_channels_and_rejects_unknown_channel_keys(): void
    {
        Sanctum::actingAs($this->userA);

        $this->patchJson('/api/v1/me/notification-preferences', [
            'channels' => ['line' => true, 'push' => false],
        ])
            ->assertOk()
            ->assertJsonPath('data.channels.in_app', true)
            ->assertJsonPath('data.channels.push', false)
            ->assertJsonPath('data.channels.line', true);

        $this->useWorkspace();
        $stored = CommunicationPreference::query()->where('user_id', $this->userA->id)->firstOrFail();
        $this->assertTrue($stored->channelEnabled('in_app'));
        $this->assertFalse($stored->channelEnabled('push'));
        $this->assertTrue($stored->channelEnabled('line'));

        $this->patchJson('/api/v1/me/notification-preferences', [
            'channels' => ['sms' => true],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');
    }

    // -- NOTIF-T03 ----------------------------------------------------------

    public function test_notif_t03_patch_rejects_consent_fields_without_touching_consent_records(): void
    {
        $this->useWorkspace();
        ConsentRecord::factory()->create();

        $before = ConsentRecord::query()->count();
        $this->assertSame(1, $before);

        Sanctum::actingAs($this->userA);

        // A successful preference change is not a consent action either.
        $this->patchJson('/api/v1/me/notification-preferences', [
            'channels' => ['push' => false],
            'digest' => 'daily',
        ])->assertOk();

        $this->useWorkspace();
        $this->assertSame($before, ConsentRecord::query()->count());

        $this->patchJson('/api/v1/me/notification-preferences', [
            'channels' => ['push' => false],
            'consent' => 'withdrawn',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->useWorkspace();
        $this->assertSame($before, ConsentRecord::query()->count());

        $stored = CommunicationPreference::query()
            ->where('user_id', $this->userA->id)
            ->firstOrFail();

        $this->assertFalse($stored->channelEnabled('push'));
        $this->assertSame('daily', $stored->digest, 'The rejected request must not apply any field.');
    }

    // -- NOTIF-T04 ----------------------------------------------------------

    public function test_notif_t04_dispatcher_skips_a_disabled_channel_without_creating_a_row(): void
    {
        $summary = $this->dispatch(templateKey: 'task.due_soon', params: [
            'due_at' => '2026-10-24T10:00:00+00:00',
        ]);

        // Defaults: in_app + push on, line off.
        $this->assertSame(0, $this->deliveriesFor($this->userA, 'line')->count());
        $this->assertSame(1, $this->deliveriesFor($this->userA, 'in_app')->count());
        $this->assertSame(1, $this->deliveriesFor($this->userA, 'push')->count());
        $this->assertSame(1, $summary['skipped']);
    }

    // -- NOTIF-T05 ----------------------------------------------------------

    public function test_notif_t05_quiet_hours_queue_push_to_window_end_but_in_app_is_sent_immediately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 23:30:00', 'UTC'));

        CommunicationPreference::query()->create([
            'user_id' => $this->userA->id,
            'locale' => 'th',
            'channels' => ['in_app' => true, 'push' => true, 'line' => false],
            'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
            'digest' => 'immediate',
        ]);

        $this->dispatch(templateKey: 'task.due_soon', params: [
            'due_at' => '2026-10-24T10:00:00+00:00',
        ]);

        $inApp = $this->deliveriesFor($this->userA, 'in_app')->first();
        $push = $this->deliveriesFor($this->userA, 'push')->first();

        $this->assertNotNull($inApp, 'expected an in_app delivery');
        $this->assertNotNull($push, 'expected a push delivery');

        $this->assertSame(NotificationDelivery::STATUS_SENT, $inApp->status);
        $this->assertNotNull($inApp->sent_at);

        $this->assertSame(NotificationDelivery::STATUS_QUEUED, $push->status);
        $this->assertNull($push->sent_at);
        $this->assertNotNull($push->scheduled_for);
        $this->assertTrue(
            $push->scheduled_for->equalTo(Carbon::parse('2026-10-24 07:00:00', 'UTC')),
            "Expected quiet-hours end 2026-10-24 07:00 UTC, got {$push->scheduled_for->toIso8601String()}",
        );
    }

    // -- NOTIF-T06 ----------------------------------------------------------

    public function test_notif_t06_phi_shaped_params_are_rejected_and_no_row_is_written(): void
    {
        try {
            $this->dispatch(templateKey: 'task.assigned', params: [
                'name' => 'Somchai',
                'value' => '120/80',
                'note' => 'call me',
            ]);
            $this->fail('Expected NotificationPayloadException for PHI-shaped params.');
        } catch (NotificationPayloadException) {
            // expected
        }

        $this->useWorkspace();
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    // -- NOTIF-T07 ----------------------------------------------------------

    public function test_notif_t07_unknown_template_key_is_rejected_and_no_row_is_written(): void
    {
        try {
            $this->dispatch(templateKey: 'task.not_a_real_template', params: []);
            $this->fail('Expected UnknownNotificationTemplateException.');
        } catch (UnknownNotificationTemplateException) {
            // expected
        }

        $this->useWorkspace();
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    // -- NOTIF-T08 ----------------------------------------------------------

    public function test_notif_t08_delivery_list_returns_only_the_actors_rows_in_the_same_workspace(): void
    {
        $this->useWorkspace();

        $own = $this->createDelivery($this->userA);
        $other = $this->createDelivery($this->userB);

        // Simulate the `/me` request: no workspace context, only the actor.
        TenantContext::clear();
        TenantContext::setActor($this->userA->id);

        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/v1/me/notifications/deliveries')->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertCount(1, $ids);

        // Another member's delivery is indistinguishable from one that does
        // not exist: 404, never a 403 that would confirm it.
        $this->postJson("/api/v1/me/notifications/{$other->id}/read")
            ->assertNotFound();

        $this->useWorkspace();
        // Read as its owner: the other member's row must remain unread.
        $this->forActor($this->userB, function () use ($other): void {
            $this->assertNull(NotificationDelivery::query()->findOrFail($other->id)->read_at);
        });
    }

    // -- NOTIF-T09 ----------------------------------------------------------

    public function test_notif_t09_marking_a_delivery_read_is_idempotent_and_sets_read_at_once(): void
    {
        $this->useWorkspace();
        $delivery = $this->createDelivery($this->userA);

        TenantContext::clear();
        Sanctum::actingAs($this->userA);

        $first = $this->postJson("/api/v1/me/notifications/{$delivery->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $delivery->id)
            ->json('data.read_at');

        $this->assertNotNull($first);

        Carbon::setTestNow(Carbon::parse('2026-10-24 08:00:00', 'UTC'));

        $second = $this->postJson("/api/v1/me/notifications/{$delivery->id}/read")
            ->assertOk()
            ->json('data.read_at');

        $this->assertSame($first, $second);

        $this->useWorkspace();
        $this->forActor($this->userA, function () use ($delivery): void {
            $this->assertNotNull(NotificationDelivery::query()->findOrFail($delivery->id)->read_at);
        });
    }

    // -- NOTIF-T10 ----------------------------------------------------------

    public function test_notif_t10_push_and_line_stubs_fail_as_not_configured_and_log_only_the_template_key(): void
    {
        CommunicationPreference::query()->create([
            'user_id' => $this->userA->id,
            'locale' => 'th',
            'channels' => ['in_app' => true, 'push' => true, 'line' => true],
            'quiet_hours' => null,
            'digest' => 'immediate',
        ]);

        Log::spy();

        $this->dispatch(templateKey: 'task.assigned', params: [
            'tier' => 'routine',
            'due_at' => '2026-10-24T10:00:00+00:00',
        ]);

        foreach (['push', 'line'] as $channel) {
            $delivery = $this->deliveriesFor($this->userA, $channel)->first();
            $this->assertNotNull($delivery, "expected a {$channel} delivery");

            $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
            $this->assertSame(NotificationDelivery::ERROR_NOT_CONFIGURED, $delivery->error_code);
        }

        Log::shouldHaveReceived('info')
            ->with('notification.push.not_configured', ['template_key' => 'task.assigned'])
            ->once();

        Log::shouldHaveReceived('info')
            ->with('notification.line.not_configured', ['template_key' => 'task.assigned'])
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return ! array_key_exists('params', $context)
                    && ! array_key_exists('due_at', $context)
                    && ! array_key_exists('tier', $context);
            })
            ->twice();
    }

    // -- NOTIF-T11 ----------------------------------------------------------

    public function test_notif_t11_every_dispatch_writes_an_audit_event_without_params(): void
    {
        $this->dispatch(templateKey: 'appt.reminder', params: [
            'scheduled_at' => '2026-10-25T09:00:00+00:00',
        ]);

        $event = AuditEvent::query()
            ->where('action', 'notification.dispatch')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('system', $event->actor_type);
        $this->assertSame('appt.reminder', $event->metadata['template_key'] ?? null);
        $this->assertArrayNotHasKey('params', $event->metadata);
        $this->assertArrayNotHasKey('scheduled_at', $event->metadata);
    }

    // -- NOTIF-T12 ----------------------------------------------------------

    public function test_notif_t12_disabled_in_app_channel_still_produces_an_audit_trail(): void
    {
        CommunicationPreference::query()->create([
            'user_id' => $this->userA->id,
            'locale' => 'th',
            'channels' => ['in_app' => false, 'push' => false, 'line' => false],
            'quiet_hours' => null,
            'digest' => 'immediate',
        ]);

        $summary = $this->dispatch(templateKey: 'bg.activated', params: [
            'activated_at' => '2026-10-23T12:00:00+00:00',
        ]);

        $this->assertSame(0, NotificationDelivery::query()->count());
        $this->assertSame(3, $summary['skipped']);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'notification.dispatch')->count(),
        );
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     * @return array{sent: int, queued: int, failed: int, skipped: int, deliveries: list<string>}
     */
    private function dispatch(string $templateKey, array $params): array
    {
        return app(NotificationDispatcher::class)->dispatch(
            $templateKey,
            [$this->userA],
            $params,
            [
                'tenant_id' => $this->tenant->id,
                'workspace_id' => $this->workspace->id,
                'subject_type' => 'care_task',
            ],
        );
    }

    private function createDelivery(User $recipient): NotificationDelivery
    {
        return NotificationDelivery::query()->create([
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'recipient_user_id' => $recipient->id,
            'channel' => NotificationDelivery::CHANNEL_IN_APP,
            'template_key' => 'task.due_soon',
            'params' => ['due_at' => '2026-10-24T10:00:00+00:00'],
            'status' => NotificationDelivery::STATUS_SENT,
            'attempts' => 1,
            'sent_at' => now(),
        ]);
    }

    /**
     * Deliveries for one recipient/channel, read with that recipient's actor
     * context. The query is EXECUTED inside the context (returning a builder
     * would run it later, after the context has been restored).
     */
    private function deliveriesFor(User $recipient, string $channel): Collection
    {
        return $this->forActor($recipient, fn () => NotificationDelivery::query()
            ->where('recipient_user_id', $recipient->id)
            ->where('channel', $channel)
            ->get());
    }

    /**
     * Run a callback with the given user as the RLS actor, restoring the
     * previous actor afterwards.
     */
    private function forActor(User $user, callable $callback): mixed
    {
        $previous = TenantContext::actorId();
        TenantContext::setActor($user->id);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContext::clearActor();
            } else {
                TenantContext::setActor($previous);
            }
        }
    }

    private function useWorkspace(): void
    {
        TenantContext::set($this->tenant->id, $this->workspace->id);
    }
}
