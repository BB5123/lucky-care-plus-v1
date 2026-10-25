<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\Notifications\Models\CommunicationPreference;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Retention\Domain\RetentionState;
use App\Modules\Retention\Mail\RetentionNoticeMail;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesWorkspaces;
use Tests\Concerns\SetsWorkspaceState;
use Tests\TestCase;

/**
 * RET-N01..N07 - the 30/7/1-day retention notices (P3, R7).
 *
 * The notice is legal, so it overrides channel preferences, reaches an account
 * that is already `scheduled_deletion`, is delivered in-app and by email, and
 * is idempotent through the delivery ledger.
 */
class RetentionNoticeTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;
    use SetsWorkspaceState;

    private Tenant $tenant;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $this->tenant = $this->createTenant('retention-notice-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Retention Notice Workspace');

        $this->user = User::factory()->create([
            'email' => 'owner@example.test',
        ]);
        $this->addMember($this->workspace, $this->user, 'profile_owner');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_ret_n01_the_30_day_notice_is_delivered_in_app_and_by_email(): void
    {
        Mail::fake();
        $this->setWorkspaceState(RetentionState::ScheduledDeletion);

        $this->artisan('retention:notices')->assertSuccessful();

        $rows = $this->deliveries('retention.notice_30');

        $inApp = $rows->firstWhere('channel', NotificationDelivery::CHANNEL_IN_APP);
        $email = $rows->firstWhere('channel', NotificationDelivery::CHANNEL_EMAIL);

        $this->assertNotNull($inApp, 'The notice must be delivered in-app.');
        $this->assertNotNull($email, 'The notice must also be delivered by email (R7).');
        $this->assertSame(NotificationDelivery::STATUS_SENT, $inApp->status);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $email->status);
        $this->assertSame(['days_left' => 30], $inApp->params);
        $this->assertSame($this->workspace->id, $inApp->subject_id);
        $this->assertSame('workspace', $inApp->subject_type);

        Mail::assertSent(RetentionNoticeMail::class, 1);
        Mail::assertSent(
            RetentionNoticeMail::class,
            static fn (RetentionNoticeMail $mail): bool => $mail->noticeKey === 'retention.notice_30',
        );
    }

    public function test_ret_n02_the_same_notice_is_never_sent_twice(): void
    {
        Mail::fake();
        $this->setWorkspaceState(RetentionState::ScheduledDeletion);

        $this->artisan('retention:notices')->assertSuccessful();
        $this->artisan('retention:notices')->assertSuccessful();

        $this->assertCount(2, $this->deliveries('retention.notice_30'));
        Mail::assertSent(RetentionNoticeMail::class, 1);
    }

    public function test_ret_n03_the_seven_day_notice_follows_the_window(): void
    {
        Mail::fake();
        $this->setWorkspaceState(RetentionState::ScheduledDeletion);
        $this->travel(23)->days();

        $this->artisan('retention:notices')->assertSuccessful();

        $this->assertCount(2, $this->deliveries('retention.notice_7'));
        $this->assertCount(0, $this->deliveries('retention.notice_30'));
    }

    public function test_ret_n04_the_one_day_notice_is_the_last_one(): void
    {
        Mail::fake();
        $this->setWorkspaceState(RetentionState::ScheduledDeletion);
        $this->travel(29)->days();

        $this->artisan('retention:notices')->assertSuccessful();

        $rows = $this->deliveries('retention.notice_1');
        $this->assertCount(2, $rows);
        $this->assertSame(['days_left' => 1], $rows->first()->params);
    }

    public function test_ret_n05_an_active_workspace_receives_no_notice(): void
    {
        Mail::fake();

        $this->artisan('retention:notices')->assertSuccessful();

        $this->assertCount(0, $this->deliveries('retention.notice_30'));
        $this->assertCount(0, $this->deliveries('retention.notice_7'));
        $this->assertCount(0, $this->deliveries('retention.notice_1'));
        Mail::assertNothingSent();
    }

    public function test_ret_n06_a_dry_run_writes_nothing_and_sends_nothing(): void
    {
        Mail::fake();
        $this->setWorkspaceState(RetentionState::ScheduledDeletion);

        $this->artisan('retention:notices', ['--dry-run' => true])->assertSuccessful();

        $this->assertCount(0, $this->deliveries('retention.notice_30'));
        Mail::assertNothingSent();
    }

    public function test_ret_n07_the_notice_overrides_a_disabled_preference_and_reports_a_missing_email(): void
    {
        Mail::fake();

        // The member turned in-app off; the legal notice must still go out.
        CommunicationPreference::query()->create([
            'user_id' => $this->user->id,
            'locale' => 'th',
            'channels' => ['in_app' => false, 'push' => false, 'line' => false],
            'quiet_hours' => null,
            'digest' => 'immediate',
        ]);

        $this->user->forceFill(['email' => ''])->save();

        $this->setWorkspaceState(RetentionState::ScheduledDeletion);

        $this->artisan('retention:notices')->assertSuccessful();

        $rows = $this->deliveries('retention.notice_30');

        $inApp = $rows->firstWhere('channel', NotificationDelivery::CHANNEL_IN_APP);
        $email = $rows->firstWhere('channel', NotificationDelivery::CHANNEL_EMAIL);

        $this->assertNotNull($inApp, 'The notice must ignore a disabled in-app preference.');
        $this->assertSame(NotificationDelivery::STATUS_SENT, $inApp->status);
        $this->assertNotNull($email);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $email->status);
        $this->assertSame('recipient_email_missing', $email->error_code);
        Mail::assertNothingSent();
    }

    /**
     * The ledger's RLS policy hides rows from everyone but their owner or the
     * dispatcher's system window; a scheduled command reads it the same way.
     *
     * @return Collection<int, NotificationDelivery>
     */
    private function deliveries(string $templateKey): Collection
    {
        DB::selectOne('select set_config(?, ?, true)', ['app.notification_system', 'on']);

        try {
            return NotificationDelivery::query()
                ->where('template_key', $templateKey)
                ->where('workspace_id', $this->workspace->id)
                ->orderBy('channel')
                ->get();
        } finally {
            DB::selectOne('select set_config(?, ?, true)', ['app.notification_system', '']);
        }
    }
}
