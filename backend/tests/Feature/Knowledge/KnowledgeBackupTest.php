<?php

namespace Tests\Feature\Knowledge;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeBaseBuilder;
use Illuminate\Support\Str;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Backup and restore drill for the mutable knowledge corpus (TASK G).
 *
 * The corpus is a public reference store, not tenant health data, so these
 * tests need no PostgreSQL. Every test builds its own corpus, backup and
 * archive under `storage/framework/testing/`; the real `database/knowledge`
 * directory is only ever read (KBC-T08 asserts it is byte-for-byte unchanged).
 */
class KnowledgeBackupTest extends TestCase
{
    private string $root;

    private string $corpus;

    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/knowledge-backup-'.Str::lower(Str::random(8)));
        $this->corpus = $this->root.'/corpus';
        $this->backups = $this->root.'/backups';

        if (! is_dir($this->corpus.'/sources')) {
            mkdir($this->corpus.'/sources', 0775, true);
        }

        if (! is_dir($this->corpus.'/benchmark')) {
            mkdir($this->corpus.'/benchmark', 0775, true);
        }

        if (! is_dir($this->backups)) {
            mkdir($this->backups, 0775, true);
        }

        config([
            'knowledge.sources_path' => $this->corpus.'/sources',
            'knowledge.sqlite_path' => $this->corpus.'/health_kb.sqlite',
            'knowledge.benchmark_path' => $this->corpus.'/benchmark/queries.json',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_kbc_t01_backup_creates_archive_and_matching_checksum(): void
    {
        $this->buildCorpus();

        $this->artisan('knowledge:backup', ['--to' => $this->backups])->assertExitCode(0);

        $archive = $this->firstArchive();
        $this->assertNotNull($archive, 'the backup did not create an archive');
        $this->assertFileExists($archive.'.sha256');

        $checksum = trim((string) file_get_contents($archive.'.sha256'));

        // `sha256sum -c` format: "<hash>  <basename>".
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}  '.preg_quote(basename($archive), '/').'$/', $checksum);
        $this->assertSame(hash_file('sha256', $archive), explode('  ', $checksum)[0]);
    }

    public function test_kbc_t02_archive_contains_sources_and_sqlite(): void
    {
        $this->buildCorpus();

        $this->artisan('knowledge:backup', ['--to' => $this->backups])->assertExitCode(0);

        $members = $this->members((string) $this->firstArchive());

        $this->assertNotEmpty($members);
        $this->assertTrue(
            (bool) array_filter($members, static fn (string $m): bool => str_starts_with($m, 'sources/')),
            'no sources/ member in the archive',
        );
        $this->assertContains('health_kb.sqlite', $members);
        $this->assertContains('benchmark/queries.json', $members);
    }

    public function test_kbc_t03_restore_drill_passes_on_a_good_archive(): void
    {
        $this->buildCorpus();
        $this->artisan('knowledge:backup', ['--to' => $this->backups])->assertExitCode(0);

        $this->artisan('knowledge:restore-drill', ['--archive' => (string) $this->firstArchive()])
            ->expectsOutputToContain('membership')
            ->assertExitCode(0);
    }

    public function test_kbc_t04_truncated_source_fails_the_json_check(): void
    {
        $this->buildCorpus();
        // Build the index first, then truncate a source: the archive is now
        // internally inconsistent exactly like a corrupt backup.
        file_put_contents($this->corpus.'/sources/ddc-disease-13.json', '{"id":"ddc-disease-13","title":"Broken"');

        $this->artisan('knowledge:backup', ['--to' => $this->backups])->assertExitCode(0);

        $this->artisan('knowledge:restore-drill', ['--archive' => (string) $this->firstArchive(), '--json' => true])
            ->expectsOutputToContain('source-json')
            ->assertExitCode(1);
    }

    public function test_kbc_t05_stripped_reviewer_fails_the_review_check(): void
    {
        $this->buildCorpus(['ddc-disease-13' => true]);

        // Strip reviewed_by after the index was built: the source still parses,
        // so only the review check can catch it.
        $path = $this->corpus.'/sources/ddc-disease-13.json';
        $source = json_decode((string) file_get_contents($path), true);
        $source['reviewed_by'] = null;
        file_put_contents($path, json_encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->artisan('knowledge:backup', ['--to' => $this->backups])->assertExitCode(0);

        $this->artisan('knowledge:restore-drill', ['--archive' => (string) $this->firstArchive(), '--json' => true])
            ->expectsOutputToContain('review-fields')
            ->assertExitCode(1);
    }

    public function test_kbc_t06_empty_sqlite_fails_the_integrity_check(): void
    {
        $this->buildCorpus();
        file_put_contents($this->corpus.'/health_kb.sqlite', '');

        $this->artisan('knowledge:backup', ['--to' => $this->backups])->assertExitCode(0);

        $this->artisan('knowledge:restore-drill', ['--archive' => (string) $this->firstArchive(), '--json' => true])
            ->expectsOutputToContain('sqlite-integrity')
            ->assertExitCode(1);
    }

    public function test_kbc_t07_pruning_refuses_to_delete_the_last_archive(): void
    {
        $this->buildCorpus();

        $this->artisan('knowledge:backup', ['--to' => $this->backups])->assertExitCode(0);
        // keep-days=0 makes the existing archive a pruning candidate; the guard
        // must refuse because pruning would leave zero archives (after the
        // second backup there are two candidates and zero survivors).
        $this->artisan('knowledge:backup', ['--to' => $this->backups, '--keep-days' => '0'])
            ->expectsOutputToContain('refusing to prune')
            ->assertExitCode(0);

        $this->assertCount(2, glob($this->backups.'/knowledge-*.tar.gz') ?: []);
    }

    public function test_kbc_t08_drill_never_writes_into_the_live_corpus(): void
    {
        $this->buildCorpus();
        $this->artisan('knowledge:backup', ['--to' => $this->backups])->assertExitCode(0);

        $before = $this->fingerprint(database_path('knowledge'));

        $this->artisan('knowledge:restore-drill', ['--archive' => (string) $this->firstArchive()])->assertExitCode(0);

        $this->assertSame($before, $this->fingerprint(database_path('knowledge')));
    }

    /**
     * @param  array<string, bool>  $reviewed  id => reviewed?
     */
    private function buildCorpus(array $reviewed = []): void
    {
        $ids = ['ddc-disease-13', 'ddc-disease-44'];

        foreach ($ids as $id) {
            $isReviewed = $reviewed[$id] ?? false;

            file_put_contents($this->corpus.'/sources/'.$id.'.json', json_encode([
                'id' => $id,
                'title' => 'Test source '.$id,
                'publisher' => 'กรมควบคุมโรค',
                'url' => 'https://ddc.moph.go.th/disease_detail.php?d='.$id,
                'category' => 'disease',
                'language' => 'th',
                'fetched_at' => '2026-10-24',
                'published_at' => null,
                'review_status' => $isReviewed ? 'reviewed' : 'unreviewed',
                'reviewed_by' => $isReviewed ? 'Dr. Test' : null,
                'reviewed_at' => $isReviewed ? '2026-10-24' : null,
                'license_note' => 'test fixture',
                'headings' => ['อาการ', 'การป้องกัน'],
                'text' => str_repeat(
                    'This is a test knowledge body long enough to satisfy the minimum length rule '
                    .'and to produce at least one chunk for the index. ',
                    5,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        file_put_contents(
            $this->corpus.'/benchmark/queries.json',
            json_encode([['id' => 'Q-01', 'question' => 'test', 'expected' => 'ddc-disease-13']], JSON_PRETTY_PRINT),
        );

        (new KnowledgeBaseBuilder)->build($this->corpus.'/sources', $this->corpus.'/health_kb.sqlite');
    }

    private function firstArchive(): ?string
    {
        $files = glob($this->backups.'/knowledge-*.tar.gz') ?: [];
        sort($files);

        return $files[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function members(string $archive): array
    {
        $prefix = 'phar://'.str_replace('\\', '/', realpath($archive) ?: $archive).'/';
        $members = [];

        foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
            $pathname = str_replace('\\', '/', $file->getPathname());
            $members[] = str_starts_with($pathname, $prefix)
                ? substr($pathname, strlen($prefix))
                : $file->getFilename();
        }

        return $members;
    }

    /**
     * @return array<string, array{int, int}>
     */
    private function fingerprint(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $result = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $result[$relative] = [(int) $file->getSize(), (int) $file->getMTime()];
        }

        ksort($result);

        return $result;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
