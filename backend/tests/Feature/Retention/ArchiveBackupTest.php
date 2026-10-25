<?php

namespace Tests\Feature\Retention;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthMeasurement;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Retention\Models\WorkspaceArchiveManifest;
use App\Modules\Retention\Services\WorkspaceLifecycle;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;
use ZipArchive;

/**
 * RET-AB01..03 - the nightly archive of the encrypted bundles (gap 4).
 *
 * The database dump does not contain the bundles, so a restored database would
 * name archived accounts whose files are gone. The backup must carry every
 * bundle, record its hash, and fail when one is missing; the drill must verify
 * the outer checksum and every entry.
 */
class ArchiveBackupTest extends TestCase
{
    use CreatesWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private User $user;

    private string $profileId;

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = $this->createTenant('archive-backup-tenant');
        $this->workspace = $this->createWorkspace($this->tenant, 'Archive Backup Workspace');

        $this->user = User::factory()->create();
        $this->addMember($this->workspace, $this->user, 'profile_owner');

        TenantContext::set($this->tenant->id, $this->workspace->id);

        $person = Person::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Backup Person',
        ]);

        $profile = HealthProfile::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'person_id' => $person->id,
            'version' => 1,
        ]);

        $this->profileId = $profile->id;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
            @unlink($path.'.sha256');
        }

        TenantContext::clear();

        parent::tearDown();
    }

    public function test_ret_ab01_the_backup_carries_every_bundle_and_its_hash(): void
    {
        $this->measurement();
        $manifest = $this->archive();
        $output = $this->temporaryPath();

        $exit = Artisan::call('retention:archive-backup', ['--output' => $output, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(1, $payload['bundles']);
        $this->assertSame([], $payload['missing']);
        $this->assertSame([], $payload['mismatched']);
        $this->assertFileExists($output);
        $this->assertFileExists($output.'.sha256');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($output) === true);

        $raw = $zip->getFromName('manifest.json');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('Backup Person', $raw);
        $this->assertStringNotContainsString('70.5', $raw);

        $backupManifest = json_decode($raw, true);
        $this->assertCount(1, $backupManifest['bundles']);
        $this->assertSame((string) $manifest->id, $backupManifest['bundles'][0]['manifest_id']);
        $this->assertSame($manifest->archive_sha256, $backupManifest['bundles'][0]['sha256']);

        $entry = $zip->getFromName($backupManifest['bundles'][0]['entry']);
        $this->assertIsString($entry);
        $this->assertSame(hash('sha256', $entry), $backupManifest['bundles'][0]['sha256']);
        $this->assertStringNotContainsString('Backup Person', $entry);
        $this->assertStringNotContainsString('70.5', $entry);

        $zip->close();
    }

    public function test_ret_ab02_a_missing_bundle_fails_the_backup_but_keeps_the_archive(): void
    {
        $this->measurement();
        $manifest = $this->archive();
        Storage::disk('local')->delete((string) $manifest->archive_path);

        $output = $this->temporaryPath();

        $exit = Artisan::call('retention:archive-backup', ['--output' => $output]);
        $out = Artisan::output();

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('1 bundle(s) are missing', $out);
        $this->assertFileExists($output);
    }

    public function test_ret_ab03_the_drill_verifies_and_detects_a_bad_checksum(): void
    {
        $this->measurement();
        $this->archive();
        $output = $this->temporaryPath();

        $this->assertSame(0, Artisan::call('retention:archive-backup', ['--output' => $output]));

        $exit = Artisan::call('retention:archive-drill-backup', ['archive' => $output, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue($payload['checksum_verified']);
        $this->assertSame(1, $payload['bundles']);
        $this->assertSame([], $payload['problems']);

        // A wrong outer checksum must fail the drill.
        file_put_contents($output.'.sha256', str_repeat('0', 64).'  '.basename($output).PHP_EOL);

        $exit = Artisan::call('retention:archive-drill-backup', ['archive' => $output]);
        $out = Artisan::output();

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('Outer checksum: NO', $out);
    }

    private function archive(): WorkspaceArchiveManifest
    {
        $lifecycle = app(WorkspaceLifecycle::class);
        $lifecycle->lapse($this->workspace);

        $this->travel(31)->days();
        $lifecycle->advance($this->workspace->fresh());

        return WorkspaceArchiveManifest::query()->where('workspace_id', $this->workspace->id)->sole();
    }

    private function measurement(): HealthMeasurement
    {
        return HealthMeasurement::query()->forceCreate([
            'id' => Uuid7::generate(),
            'tenant_id' => $this->tenant->id,
            'workspace_id' => $this->workspace->id,
            'profile_id' => $this->profileId,
            'type' => 'weight',
            'value' => 70.5,
            'unit' => 'kg',
            'recorded_at' => now()->subDay(),
            'recorded_by' => $this->user->id,
            'source' => 'manual',
            'version' => 1,
        ]);
    }

    private function temporaryPath(): string
    {
        $path = sys_get_temp_dir().'/retention-backup-'.Uuid7::generate().'.zip';
        $this->temporary[] = $path;

        return $path;
    }
}
