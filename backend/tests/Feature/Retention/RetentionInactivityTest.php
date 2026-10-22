<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\Retention\Services\InactivityService;
use App\Modules\Retention\Services\InactivityStatus;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-T04 — inactivity schedule (30/14/7) and purge-due flag.
 */
class RetentionInactivityTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));

        $this->tenant = $this->createTenant('retention-inactivity-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Inactivity Workspace');

        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'profile_owner');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_ret_t04_schedule_and_purge_due_are_computed_from_last_login(): void
    {
        // 20 days before the 24-month inactivity deadline.
        $this->user->forceFill([
            'last_login_at' => Carbon::now()->subMonths(24)->addDays(20),
        ])->save();

        $status = $this->statusForWorkspace();

        $this->assertSame('2026-11-11T12:00:00+00:00', $status->purgeDueAt->toIso8601String());
        $this->assertFalse($status->purgeDue);
        $this->assertSame([30, 14, 7], array_column($status->schedule, 'days_before'));
        $this->assertSame('2026-10-12T12:00:00+00:00', $status->schedule[0]['due_at']->toIso8601String());
        $this->assertSame('2026-10-28T12:00:00+00:00', $status->schedule[1]['due_at']->toIso8601String());
        $this->assertSame('2026-11-04T12:00:00+00:00', $status->schedule[2]['due_at']->toIso8601String());
        $this->assertSame([30], $status->notificationsDue);
        $this->assertSame([], app(InactivityService::class)->dueNow());

        // 21 days later the workspace is past the purge date.
        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00', 'UTC'));

        $status = $this->statusForWorkspace();

        $this->assertTrue($status->purgeDue);
        $this->assertSame([30, 14, 7], $status->notificationsDue);

        $due = app(InactivityService::class)->dueNow();
        $this->assertCount(1, $due);
        $this->assertSame($this->workspace->id, $due[0]->workspaceId);

        $this->artisan('retention:inactivity', ['--dry-run' => true])
            ->assertSuccessful();
    }

    private function statusForWorkspace(): InactivityStatus
    {
        $statuses = app(InactivityService::class)->scan();

        foreach ($statuses as $status) {
            if ($status->workspaceId === $this->workspace->id) {
                return $status;
            }
        }

        $this->fail('The inactivity scan did not return the test workspace.');
    }
}
