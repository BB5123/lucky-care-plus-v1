<?php

namespace Tests\Feature\AiGateway;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review pack for the health knowledge corpus (TASK REVIEW-PACK).
 *
 * The pack is a generated Markdown document, so these tests read the real
 * output bytes: status filtering, stale detection, excerpt bounds, idempotence
 * and the regeneration warning. Every test writes its sources and output into
 * a temporary directory; the repository document is never touched.
 */
class KnowledgeReviewPackTest extends TestCase
{
    private const TAIL = 'TAIL-MARKER-DO-NOT-PUBLISH';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));

        $this->directory = storage_path('framework/testing/review-pack-'.Str::lower(Str::random(8)));

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }

        config(['knowledge.sources_path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_command_writes_the_file_and_summary_lists_every_source(): void
    {
        $this->writeCorpus();
        $output = $this->directory.'/pack.md';

        $this->artisan('knowledge:review-pack', [
            '--output' => $output,
            '--status' => 'all',
        ])->assertExitCode(0);

        $this->assertFileExists($output);

        $contents = (string) file_get_contents($output);
        $summary = $this->summary($contents);

        $expected = [
            'ddc-disease-44' => 'unreviewed',
            'ddc-disease-13' => 'reviewed',
            'ddc-disease-25' => 'unreviewed',
        ];

        foreach ($expected as $id => $status) {
            $this->assertMatchesRegularExpression(
                '/\| `'.preg_quote($id, '/').'` \|[^\n]*\| `'.$status.'` \|/u',
                $summary,
            );
        }

        $this->assertStringContainsString('Unreviewed sources in the corpus: 2 of 3.', $contents);
        $this->assertStringContainsString('Selected for this pack: 3 of 3 sources (status filter: `all`).', $contents);
    }

    public function test_unreviewed_is_the_default_and_all_includes_reviewed_sources(): void
    {
        $this->writeCorpus();
        $output = $this->directory.'/pack.md';

        $this->artisan('knowledge:review-pack', [
            '--output' => $output,
        ])->assertExitCode(0);

        $unreviewed = (string) file_get_contents($output);

        $this->assertStringContainsString('`ddc-disease-44`', $unreviewed);
        $this->assertStringContainsString('`ddc-disease-25`', $unreviewed);
        $this->assertStringNotContainsString('ddc-disease-13', $unreviewed);
        $this->assertStringContainsString('Selected for this pack: 2 of 3 sources (status filter: `unreviewed`).', $unreviewed);

        $this->artisan('knowledge:review-pack', [
            '--output' => $output,
            '--status' => 'all',
        ])->assertExitCode(0);

        $all = (string) file_get_contents($output);

        $this->assertStringContainsString('`ddc-disease-13`', $all);
        $this->assertStringContainsString('Selected for this pack: 3 of 3 sources (status filter: `all`).', $all);
    }

    public function test_stale_source_is_flagged_in_the_summary(): void
    {
        $this->writeCorpus();
        $output = $this->directory.'/pack.md';

        $this->artisan('knowledge:review-pack', [
            '--output' => $output,
            '--status' => 'all',
        ])->assertExitCode(0);

        $summary = $this->summary((string) file_get_contents($output));

        $this->assertMatchesRegularExpression(
            '/\| `ddc-disease-25` \|[^\n]*\| yes \|/u',
            $summary,
        );
        $this->assertMatchesRegularExpression(
            '/\| `ddc-disease-44` \|[^\n]*\| no \|/u',
            $summary,
        );
    }

    public function test_excerpts_are_bounded_and_contain_no_replacement_characters(): void
    {
        $this->writeCorpus();
        $output = $this->directory.'/pack.md';

        $this->artisan('knowledge:review-pack', [
            '--output' => $output,
            '--status' => 'all',
        ])->assertExitCode(0);

        $contents = (string) file_get_contents($output);

        $this->assertStringNotContainsString("\u{FFFD}", $contents);
        $this->assertStringContainsString('ไข้สูง', $contents);

        $sources = substr($contents, (int) strpos($contents, '## Sources'));

        preg_match_all('/^> (.*)$/mu', $sources, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $excerpt) {
            $this->assertLessThanOrEqual(400, mb_strlen($excerpt));
        }

        // The marker sits after the first 400 characters of the first section.
        $this->assertStringNotContainsString(self::TAIL, $contents);
    }

    public function test_running_twice_produces_identical_bytes(): void
    {
        $this->writeCorpus();
        $output = $this->directory.'/pack.md';

        $this->artisan('knowledge:review-pack', [
            '--output' => $output,
            '--status' => 'all',
        ])->assertExitCode(0);

        $first = (string) file_get_contents($output);

        $this->artisan('knowledge:review-pack', [
            '--output' => $output,
            '--status' => 'all',
        ])->assertExitCode(0);

        $second = (string) file_get_contents($output);

        $this->assertSame($first, $second);
    }

    public function test_generated_file_states_the_command_and_that_it_must_not_be_edited(): void
    {
        $this->writeCorpus();
        $output = $this->directory.'/pack.md';

        $this->artisan('knowledge:review-pack', [
            '--output' => $output,
            '--status' => 'all',
        ])->assertExitCode(0);

        $contents = (string) file_get_contents($output);

        $this->assertStringContainsString('php artisan knowledge:review-pack', $contents);
        $this->assertStringContainsString('do not edit this file by hand', $contents);
        $this->assertStringContainsString('Generated on 2026-10-25', $contents);
    }

    private function summary(string $contents): string
    {
        $start = strpos($contents, '## Summary');
        $end = strpos($contents, '## Sources');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($contents, (int) $start, (int) $end - (int) $start);
    }

    private function writeCorpus(): void
    {
        $this->writeSource('ddc-disease-44', [
            'title' => 'ไข้เลือดออก (Dengue)',
            'fetched_at' => '2026-10-20',
            'review_status' => 'unreviewed',
            'headings' => ['อาการ', 'การป้องกัน'],
            'text' => $this->sourceText(),
        ]);

        $this->writeSource('ddc-disease-13', [
            'title' => 'ไข้หวัดใหญ่ (Influenza)',
            'fetched_at' => '2026-10-20',
            'review_status' => 'reviewed',
            'reviewed_by' => 'Dr. Reviewer',
            'reviewed_at' => '2026-10-24',
            'headings' => ['อาการ', 'การป้องกัน'],
            'text' => $this->sourceText(),
        ]);

        $this->writeSource('ddc-disease-25', [
            'title' => 'พิษสุนัขบ้า (Rabies)',
            'fetched_at' => '2025-10-01',
            'review_status' => 'unreviewed',
            'headings' => [],
            'text' => $this->sourceText(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function writeSource(string $id, array $overrides): void
    {
        $source = array_merge([
            'id' => $id,
            'title' => 'แหล่งข้อมูลทดสอบ',
            'publisher' => 'กรมควบคุมโรค กระทรวงสาธารณสุข',
            'url' => 'https://ddc.moph.go.th/disease_detail.php?d='.(crc32($id) % 1000),
            'category' => 'disease',
            'language' => 'th',
            'fetched_at' => '2026-10-20',
            'published_at' => null,
            'review_status' => 'unreviewed',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'license_note' => 'test',
            'headings' => ['อาการ', 'การป้องกัน'],
            'text' => $this->sourceText(),
        ], $overrides);

        file_put_contents(
            $this->directory.'/'.$id.'.json',
            json_encode(
                $source,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n",
        );
    }

    private function sourceText(): string
    {
        return "อาการ\n\n"
            .str_repeat('ไข้สูง ปวดศีรษะ ปวดกล้ามเนื้อ และอาจมีผื่นขึ้น ', 20)
            .self::TAIL
            ."\n\nการป้องกัน\n\n"
            .str_repeat('ล้างมือบ่อย ๆ หลีกเลี่ยงสถานที่แออัด และพบแพทย์เมื่ออาการรุนแรง ', 20);
    }
}
