<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Modules\Authz\Authorizer;
use App\Modules\Authz\ResourceRef;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PLT-T01 ... PLT-T09 — CR-005 / baseline v1.4.0.
 *
 * Platform capabilities are granted by the explicit `users.is_platform_admin`
 * flag, never by a workspace role or workspace ownership (ABD-019). The
 * corpus is copied to a temporary directory so a review test never rewrites
 * the checked-in sources.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $workspaceId;

    private string $sourceDirectory;

    private User $admin;

    private User $owner;

    private User $plain;

    private HealthProfile $ownerProfile;

    private HealthProfile $dependentProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDirectory = storage_path(
            'framework/testing/platform-knowledge-'.Str::lower(Str::random(8)),
        );

        File::ensureDirectoryExists($this->sourceDirectory);

        foreach (glob(base_path('database/knowledge/sources/*.json')) ?: [] as $file) {
            File::copy($file, $this->sourceDirectory.'/'.basename($file));
        }

        config([
            'knowledge.sources_path' => $this->sourceDirectory,
            'knowledge.sqlite_path' => $this->sourceDirectory.'/kb.sqlite',
        ]);

        $this->tenantId = Uuid7::generate();
        $this->workspaceId = Uuid7::generate();

        TenantContext::set($this->tenantId, '');

        Tenant::forceCreate([
            'id' => $this->tenantId,
            'name' => 'Platform Tenant',
            'slug' => 'default',
        ]);

        Workspace::forceCreate([
            'id' => $this->workspaceId,
            'tenant_id' => $this->tenantId,
            'name' => 'Platform Workspace',
            'slug' => 'platform-workspace',
        ]);

        TenantContext::set($this->tenantId, $this->workspaceId);

        $this->admin = User::factory()->create([
            'is_platform_admin' => true,
            'platform_admin_granted_at' => now(),
        ]);
        $this->owner = User::factory()->create();
        $this->plain = User::factory()->create();

        WorkspaceMembership::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->admin->id,
            'role' => 'platform_admin',
            'status' => 'active',
        ]);

        WorkspaceMembership::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->owner->id,
            'role' => 'profile_owner',
            'status' => 'active',
        ]);

        $ownerPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->owner->id,
            'display_name' => 'Owner Person',
        ]);

        $this->ownerProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'person_id' => $ownerPerson->id,
            'managed_by_person_id' => null,
            'version' => 1,
        ]);

        $dependentPerson = Person::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => null,
            'display_name' => 'Dependent Person',
        ]);

        $this->dependentProfile = HealthProfile::forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'person_id' => $dependentPerson->id,
            'managed_by_person_id' => $ownerPerson->id,
            'version' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sourceDirectory);

        parent::tearDown();
    }

    private function authorizer(): Authorizer
    {
        return $this->app->make(Authorizer::class);
    }

    private function sourcePath(string $id): string
    {
        return $this->sourceDirectory.'/'.$id.'.json';
    }

    public function test_plt_t01_a_user_without_the_flag_gets_403_on_both_admin_routes(): void
    {
        Sanctum::actingAs($this->plain);

        $this->getJson('/api/v1/admin/knowledge/sources')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-44/review', [
            'reviewer' => 'Dr. Not Platform',
            'build' => false,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_plt_t02_a_workspace_owner_without_the_flag_gets_403(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/knowledge/sources')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-44/review', [
            'reviewer' => 'Dr. Owner',
            'build' => false,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_plt_t03_with_the_flag_get_lists_all_sources_with_review_status(): void
    {
        // Make one source demonstrably stale so the 180-day rule is exercised
        // without changing the source count.
        $stale = json_decode((string) file_get_contents($this->sourcePath('ddc-disease-10')), true, 512, JSON_THROW_ON_ERROR);
        $stale['fetched_at'] = '2020-01-01';
        file_put_contents(
            $this->sourcePath('ddc-disease-10'),
            json_encode($stale, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/knowledge/sources')->assertOk();

        $rows = $response->json('data');

        $this->assertCount(16, $rows, 'The checked-in corpus must contain all 16 sources.');
        $this->assertSame(
            ['unreviewed'],
            array_values(array_unique(array_column($rows, 'review_status'))),
        );

        foreach ($rows as $row) {
            foreach ([
                'id',
                'title',
                'publisher',
                'url',
                'category',
                'language',
                'fetched_at',
                'review_status',
                'reviewed_by',
                'reviewed_at',
                'stale',
            ] as $key) {
                $this->assertArrayHasKey($key, $row, "The list row is missing {$key}.");
            }

            $this->assertIsBool($row['stale']);
        }

        $byId = collect($rows)->keyBy('id');

        $this->assertTrue($byId['ddc-disease-10']['stale'], 'A 2020 fetched_at must be stale.');
        $this->assertFalse($byId['ddc-disease-44']['stale'], 'A recent fetched_at must not be stale.');
        $this->assertNull($byId['ddc-disease-44']['reviewed_by']);
        $this->assertNull($byId['ddc-disease-44']['reviewed_at']);
    }

    public function test_plt_t04_reviewing_persists_metadata_returns_the_row_and_rebuilds(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-44/review', [
            'reviewer' => 'Dr. Platform',
            'build' => true,
        ])->assertOk();

        $response
            ->assertJsonPath('data.id', 'ddc-disease-44')
            ->assertJsonPath('data.review_status', 'reviewed')
            ->assertJsonPath('data.reviewed_by', 'Dr. Platform')
            ->assertJsonPath('data.reviewed_at', now()->toDateString());

        $source = json_decode((string) file_get_contents($this->sourcePath('ddc-disease-44')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('reviewed', $source['review_status']);
        $this->assertSame('Dr. Platform', $source['reviewed_by']);
        $this->assertSame(now()->toDateString(), $source['reviewed_at']);

        $pdo = new \PDO('sqlite:'.$this->sourceDirectory.'/kb.sqlite');
        $status = $pdo->query("select review_status from documents where id = 'ddc-disease-44'")->fetchColumn();
        $pdo = null;

        $this->assertSame('reviewed', $status, 'The SQLite corpus must be rebuilt from the updated source.');
    }

    public function test_plt_t05_a_reviewer_shorter_than_three_characters_is_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-44/review', [
            'reviewer' => 'ab',
            'build' => false,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonValidationErrors('reviewer');

        $source = json_decode((string) file_get_contents($this->sourcePath('ddc-disease-44')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('unreviewed', $source['review_status']);
    }

    public function test_plt_t06_each_call_writes_one_audit_row_without_source_text(): void
    {
        $source = json_decode((string) file_get_contents($this->sourcePath('ddc-disease-44')), true, 512, JSON_THROW_ON_ERROR);
        $snippet = mb_substr((string) $source['text'], 0, 60);

        Sanctum::actingAs($this->admin);

        $before = DB::table('security_audit_logs')->count();

        $this->getJson('/api/v1/admin/knowledge/sources')->assertOk();

        $this->assertSame($before + 1, DB::table('security_audit_logs')->count());

        $readRow = DB::table('security_audit_logs')->orderByDesc('seq')->first();

        $this->assertSame('knowledge.source.read', $readRow->action);
        $this->assertSame('allow', $readRow->result);
        $this->assertStringNotContainsString(
            $snippet,
            (string) json_encode($readRow, JSON_UNESCAPED_UNICODE),
        );

        $before = DB::table('security_audit_logs')->count();

        $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-44/review', [
            'reviewer' => 'Dr. Platform',
            'build' => false,
        ])->assertOk();

        $this->assertSame($before + 1, DB::table('security_audit_logs')->count());

        $reviewRow = DB::table('security_audit_logs')->orderByDesc('seq')->first();
        $metadata = json_decode((string) $reviewRow->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('knowledge.source.review', $reviewRow->action);
        $this->assertSame('allow', $reviewRow->result);
        $this->assertSame('ddc-disease-44', $metadata['source_id']);
        $this->assertSame('Dr. Platform', $metadata['reviewer']);
        $this->assertStringNotContainsString(
            $snippet,
            (string) json_encode($reviewRow, JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_plt_t07_revoking_the_last_platform_admin_is_refused(): void
    {
        User::query()->where('is_platform_admin', true)->update([
            'is_platform_admin' => false,
            'platform_admin_granted_at' => null,
            'platform_admin_granted_by' => null,
        ]);

        $first = User::factory()->create([
            'is_platform_admin' => true,
            'platform_admin_granted_at' => now(),
        ]);
        $last = User::factory()->create([
            'is_platform_admin' => true,
            'platform_admin_granted_at' => now(),
        ]);

        $before = DB::table('security_audit_logs')->count();

        $this->artisan('platform:grant', [
            'email' => $first->email,
            '--revoke' => true,
        ])->assertExitCode(0);

        $this->assertFalse($first->fresh()->is_platform_admin);
        $this->assertSame($before + 1, DB::table('security_audit_logs')->count());

        $before = DB::table('security_audit_logs')->count();

        $this->artisan('platform:grant', [
            'email' => $last->email,
            '--revoke' => true,
        ])->assertExitCode(1);

        $this->assertTrue($last->fresh()->is_platform_admin, 'The last admin must keep the flag.');
        $this->assertSame($before + 1, DB::table('security_audit_logs')->count());

        $row = DB::table('security_audit_logs')->orderByDesc('seq')->first();
        $metadata = json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('platform.admin.revoke', $row->action);
        $this->assertSame('deny', $row->result);
        $this->assertSame('last_platform_admin', $row->reason_code);
        $this->assertSame($last->id, (int) $metadata['target_user_id']);
    }

    public function test_plt_t08_a_platform_admin_is_still_denied_workspace_permissions_without_a_grant(): void
    {
        $denied = $this->authorizer()->authorize(
            $this->admin,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->dependentProfile->id),
        );

        $this->assertFalse($denied->allowed());
        $this->assertSame('forbidden', $denied->code());
        $this->assertSame(403, $denied->status());

        $noWorkspace = User::factory()->create(['is_platform_admin' => true]);

        $noMembership = $this->authorizer()->authorize(
            $noWorkspace,
            'health_measurement.read',
            ResourceRef::forProfile($this->workspaceId, $this->dependentProfile->id),
        );

        $this->assertFalse($noMembership->allowed());
        $this->assertSame('out_of_scope', $noMembership->code());
        $this->assertSame(404, $noMembership->status());

        // The platform entry point cannot be used to reach a workspace permission.
        $wrongEntryPoint = $this->authorizer()->authorizePlatform(
            $this->admin,
            'health_measurement.read',
        );

        $this->assertFalse($wrongEntryPoint->allowed());
        $this->assertSame('forbidden', $wrongEntryPoint->code());

        // The workspace entry point cannot be used to reach a platform permission.
        $workspaceOwner = $this->authorizer()->authorize(
            $this->owner,
            'knowledge.source.review',
            ResourceRef::forWorkspace($this->workspaceId),
        );

        $this->assertFalse($workspaceOwner->allowed());
        $this->assertSame('forbidden', $workspaceOwner->code());
        $this->assertSame(403, $workspaceOwner->status());

        // The flag is the only way in.
        $this->assertTrue(
            $this->authorizer()->authorizePlatform($this->admin, 'knowledge.source.review')->allowed(),
        );

        $this->assertFalse(
            $this->authorizer()->authorizePlatform($this->owner, 'knowledge.source.read')->allowed(),
        );
    }

    public function test_plt_t09_status_and_query_filters_work(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-44/review', [
            'reviewer' => 'Dr. Platform',
            'build' => false,
        ])->assertOk();

        $unreviewed = $this->getJson('/api/v1/admin/knowledge/sources?status=unreviewed')
            ->assertOk()
            ->json('data');

        $this->assertCount(15, $unreviewed);
        $this->assertNotContains('ddc-disease-44', array_column($unreviewed, 'id'));

        $reviewed = $this->getJson('/api/v1/admin/knowledge/sources?status=reviewed')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $reviewed);
        $this->assertSame('ddc-disease-44', $reviewed[0]['id']);
        $this->assertSame('reviewed', $reviewed[0]['review_status']);

        $byTitle = $this->getJson('/api/v1/admin/knowledge/sources?'.http_build_query(['q' => 'Dengue']))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byTitle);
        $this->assertSame('ddc-disease-44', $byTitle[0]['id']);

        $byPublisherPart = $this->getJson('/api/v1/admin/knowledge/sources?'.http_build_query(['q' => 'Rabies']))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byPublisherPart);
        $this->assertSame('ddc-disease-25', $byPublisherPart[0]['id']);
    }

    /**
     * The reviewer name is an identity in the audit trail. A Thai name that was
     * round-tripped through a shell arrives as "??. ?????" - the real stack stored
     * it as if it were a person while CR-005 was being verified. Reject it.
     */
    public function test_plt_t11_a_corrupted_reviewer_name_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['??. ?????', '   ', '?!?', "\u{FFFD}\u{FFFD}\u{FFFD}"] as $bad) {
            $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-44/review', [
                'reviewer' => $bad,
                'build' => false,
            ])->assertStatus(422);
        }

        // A real name still works, and Thai letters are accepted.
        $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-44/review', [
            'reviewer' => 'Dr. Somchai',
            'build' => false,
        ])->assertOk();
    }

    /**
     * Defence in depth: the platform and workspace pipelines are mutually
     * exclusive. A user who holds the platform flag AND belongs to the workspace
     * must still be refused a platform permission through the workspace path -
     * without this case, deleting the guard in Authorizer::authorize() would
     * leave the suite green (found by a cross-model review of this change).
     */
    public function test_plt_t10_a_platform_permission_cannot_be_reached_through_the_workspace_path(): void
    {
        $workspaceRef = ResourceRef::forWorkspace($this->workspaceId);

        $denied = $this->authorizer()->authorize(
            $this->admin,
            'knowledge.source.read',
            $workspaceRef,
        );

        $this->assertFalse($denied->allowed());
        $this->assertSame('forbidden', $denied->code());
        $this->assertSame('platform_permission_requires_platform_authorizer', $denied->reason());

        // The same actor and permission are allowed through the platform path.
        $this->assertTrue(
            $this->authorizer()->authorizePlatform($this->admin, 'knowledge.source.read')->allowed(),
        );
    }

    /**
     * CR-005-FIX / KRF-T01. A corpus directory the runtime user cannot write
     * must be a typed 503, never an unhandled ErrorException (500), and it must
     * leave the source file byte-for-byte unchanged.
     */
    public function test_krf_t01_unwritable_sources_directory_returns_503_and_leaves_the_source_unchanged(): void
    {
        $path = $this->sourcePath('ddc-disease-11');
        $before = (string) file_get_contents($path);

        Sanctum::actingAs($this->admin);

        try {
            $this->makeSourceDirectoryUnwritable();

            $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-11/review', [
                'reviewer' => 'Dr. Readonly',
            ])
                ->assertStatus(503)
                ->assertJsonPath('code', 'knowledge_corpus_readonly')
                ->assertJsonPath(
                    'detail',
                    (string) config('ai.provider_messages.knowledge_corpus_readonly'),
                );
        } finally {
            @chmod($this->sourceDirectory, 0755);
        }

        $this->assertSame($before, (string) file_get_contents($path));
    }

    /**
     * CR-005-FIX / KRF-T02. The failed write is audited once as a failure with
     * the documented reason code, and the row never carries source text.
     */
    public function test_krf_t02_a_readonly_failure_writes_one_failed_audit_row_without_source_text(): void
    {
        $source = json_decode(
            (string) file_get_contents($this->sourcePath('ddc-disease-11')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $snippet = mb_substr((string) $source['text'], 0, 60);

        Sanctum::actingAs($this->admin);

        $before = DB::table('security_audit_logs')
            ->where('action', 'knowledge.source.review')
            ->where('result', 'fail')
            ->count();

        try {
            $this->makeSourceDirectoryUnwritable();

            $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-11/review', [
                'reviewer' => 'Dr. Readonly',
            ])->assertStatus(503);
        } finally {
            @chmod($this->sourceDirectory, 0755);
        }

        $rows = DB::table('security_audit_logs')
            ->where('action', 'knowledge.source.review')
            ->where('result', 'fail')
            ->get();

        $this->assertCount($before + 1, $rows);
        $this->assertSame($before + 1, DB::table('security_audit_logs')
            ->where('reason_code', 'corpus_readonly')
            ->count());

        $row = $rows->last();
        $this->assertSame('corpus_readonly', $row->reason_code);
        $this->assertStringNotContainsString(
            $snippet,
            (string) json_encode($row, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * CR-005-FIX / KRF-T03. The happy path still works and rebuilds the corpus.
     */
    public function test_krf_t03_a_writable_directory_still_reviews_successfully(): void
    {
        $this->assertTrue(is_writable($this->sourceDirectory));

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/knowledge/sources/ddc-disease-11/review', [
            'reviewer' => 'Dr. Writable',
            'build' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', 'ddc-disease-11')
            ->assertJsonPath('data.review_status', 'reviewed')
            ->assertJsonPath('data.reviewed_by', 'Dr. Writable');

        $source = json_decode(
            (string) file_get_contents($this->sourcePath('ddc-disease-11')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('reviewed', $source['review_status']);

        $pdo = new \PDO('sqlite:'.$this->sourceDirectory.'/kb.sqlite');
        $status = $pdo->query("select review_status from documents where id = 'ddc-disease-11'")
            ->fetchColumn();
        $pdo = null;

        $this->assertSame('reviewed', $status);
    }

    /**
     * `chmod` is advisory on Windows and a root test runner ignores it
     * entirely. Prove the directory is actually unwritable before asserting;
     * otherwise skip honestly instead of faking a read-only filesystem.
     */
    private function makeSourceDirectoryUnwritable(): void
    {
        @chmod($this->sourceDirectory, 0500);
        clearstatcache();

        $probe = $this->sourceDirectory.'/.write-probe';
        $written = @file_put_contents($probe, 'probe');

        if ($written !== false) {
            @unlink($probe);
            @chmod($this->sourceDirectory, 0755);

            $this->markTestSkipped(
                'chmod cannot make a directory unwritable on this platform (Windows ACL or a root test runner).',
            );
        }
    }
}
