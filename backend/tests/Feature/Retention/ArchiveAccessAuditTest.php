<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * RET-AUD01 - reading an archive from the console is audited.
 *
 * The HTTP gate keeps archived data out of the app, so the console manifest and
 * drill commands are the remaining readers. Each successful read writes one
 * `retention.archive_read` row (command + result, no PHI). The weekly
 * `retention:status --verify-archives` probe is intentionally not audited; this
 * test pins that too, because a noisy audit is as useless as none.
 */
class ArchiveAccessAuditTest extends TestCase
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

        $this->tenant = $this->createTenant('archive-audit-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Archive Audit Workspace');

        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'profile_owner');

        TenantContext::set($this->tenant->id, $this->workspace->id);

        $person = Person::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Audit Person',
        ]);

        HealthProfile::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $person->id,
            'version' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_ret_aud01_console_reads_are_audited_but_the_probe_is_not(): void
    {
        $this->archive();

        $this->artisan('retention:archive-manifest', ['workspace' => $this->workspace->id])->assertSuccessful();
        $this->artisan('retention:archive-drill', ['workspace' => $this->workspace->id])->assertSuccessful();

        $rows = $this->reads();

        $this->assertCount(2, $rows);
        $this->assertSame('archive-manifest', $rows[0]['metadata']['command']);
        $this->assertSame('archive-drill', $rows[1]['metadata']['command']);
        $this->assertTrue($rows[1]['metadata']['verified']);

        Artisan::call('retention:status', ['--verify-archives' => true]);

        $this->assertCount(2, $this->reads());
    }

    /**
     * @return list<array{metadata: array<string, mixed>}>
     */
    private function reads(): array
    {
        return DB::table('security_audit_logs')
            ->where('action', 'retention.archive_read')
            ->where('target_id', $this->workspace->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => [
                'metadata' => json_decode((string) $row->metadata, true),
            ])
            ->all();
    }

    private function archive(): void
    {
        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);

        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());
    }
}
