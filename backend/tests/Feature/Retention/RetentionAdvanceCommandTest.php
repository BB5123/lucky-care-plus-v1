<?php

namespace Tests\Feature\Retention;

use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Retention\Services\ArchiveBundleService;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-ADV01..02 - the nightly advance command, including the P4b-1 archive write.
 *
 * The command is what actually runs at 03:40 UTC. It has to do two things the
 * service tests cannot show: report the transition in its table, and keep
 * processing the other tenants when one workspace fails (the archive bundle is
 * file I/O - a full disk must not silently stop the whole night).
 */
class RetentionAdvanceCommandTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = $this->createTenant('advance-command-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Advance Workspace');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_ret_adv01_a_due_workspace_is_archived_with_its_bundle(): void
    {
        $this->lapse();

        $this->travel(31)->days();

        $exit = Artisan::call('retention:advance');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('advanced', $output);

        TenantContext::run($this->tenant->id, $this->workspace->id, function (): void {
            $manifest = WorkspaceArchiveManifest::query()->where('workspace_id', $this->workspace->id)->sole();

            $this->assertNotNull($manifest->archive_path);
            Storage::disk('local')->assertExists($manifest->archive_path);
        });
    }

    public function test_ret_adv02_a_failing_bundle_is_reported_and_does_not_stop_the_batch(): void
    {
        $second = $this->createWorkspace($this->tenant, 'Second Lapsed Workspace');

        $this->lapse();
        $this->lapse($second);

        $this->travel(31)->days();

        $this->mock(ArchiveBundleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('write')->andThrow(new RuntimeException('disk unavailable'));
        });

        $exit = Artisan::call('retention:advance');
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('failed', $output);
        // Both workspaces were attempted, not just the first one (the summary
        // line also contains the word "failed", so count the table cells).
        $this->assertSame(2, substr_count($output, '| failed'), $output);

        TenantContext::run($this->tenant->id, $this->workspace->id, function (): void {
            $this->assertSame('read_only', $this->workspace->fresh()->retention_state->value);
            $this->assertSame(0, WorkspaceArchiveManifest::query()->where('workspace_id', $this->workspace->id)->count());
        });
    }

    private function lapse(?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;

        TenantContext::run($this->tenant->id, $workspace->id, function () use ($workspace): void {
            app(WorkspaceLifecycle::class)->lapse($workspace);
        });
    }
}
