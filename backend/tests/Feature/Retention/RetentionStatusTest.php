<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-STAT01..03 - the operator report (`retention:status`).
 *
 * The report is what an operator reads before turning retention on: how many
 * accounts sit in each state, which are due, how many are held, and (opt-in)
 * whether every archive verifies. The exit code matters as much as the table:
 * `--verify-archives` is usable as a monitoring probe, so a broken bundle must
 * make it non-zero.
 */
class RetentionStatusTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = $this->createTenant('retention-status-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Status Workspace');
        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'profile_owner');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_ret_stat01_reports_state_counts_and_what_is_due(): void
    {
        $second = $this->createWorkspace($this->tenant, 'Lapsed Workspace');

        TenantContext::run($this->tenant->id, $second->id, function () use ($second): void {
            app(WorkspaceLifecycle::class)->lapse($second);
        });

        $this->travel(31)->days();

        $exit = Artisan::call('retention:status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $payload['states']['active']);
        $this->assertSame(1, $payload['states']['read_only']);
        $this->assertCount(1, $payload['due']);
        $this->assertSame($second->id, $payload['due'][0]['workspace_id']);
        $this->assertSame('read_only', $payload['due'][0]['state']);
        $this->assertSame(0, $payload['held']);
    }

    public function test_ret_stat02_verify_archives_passes_and_fails_on_a_broken_bundle(): void
    {
        $archived = $this->createWorkspace($this->tenant, 'Archived Workspace');

        TenantContext::run($this->tenant->id, $archived->id, function () use ($archived): void {
            $lifecycle = app(WorkspaceLifecycle::class);
            $lifecycle->lapse($archived);
        });

        $this->travel(31)->days();

        TenantContext::run($this->tenant->id, $archived->id, function () use ($archived): void {
            app(WorkspaceLifecycle::class)->advance($archived->fresh());
        });

        TenantContext::clear();

        $exit = Artisan::call('retention:status', ['--json' => true, '--verify-archives' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $payload['archives']);
        $this->assertTrue($payload['archives'][0]['bundle_ok']);
        $this->assertTrue($payload['archives'][0]['inventory_ok']);

        $manifest = TenantContext::run(
            $this->tenant->id,
            $archived->id,
            fn (): WorkspaceArchiveManifest => WorkspaceArchiveManifest::query()
                ->where('workspace_id', $archived->id)
                ->sole(),
        );

        Storage::disk('local')->put(
            (string) $manifest->archive_path,
            (string) Storage::disk('local')->get($manifest->archive_path).'tampered',
        );

        $exit = Artisan::call('retention:status', ['--json' => true, '--verify-archives' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['archives'][0]['bundle_ok']);
    }

    public function test_ret_stat03_reports_nothing_to_verify_when_no_workspace_is_archived(): void
    {
        $exit = Artisan::call('retention:status', ['--verify-archives' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No archived workspaces to verify.', Artisan::output());
    }
}
