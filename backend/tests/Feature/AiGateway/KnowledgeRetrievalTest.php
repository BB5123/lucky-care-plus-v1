<?php

namespace Tests\Feature\AiGateway;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeBaseBuilder;
use App\Modules\AiGateway\Knowledge\Application\KnowledgeRetriever;
use App\Modules\AiGateway\Knowledge\Domain\ThaiText;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK KB-2 — Thai retrieval over the real seven-source corpus.
 *
 * The corpus is rebuilt from `database/knowledge/sources` into a temporary
 * SQLite file, so these tests exercise the committed sources without touching
 * the built file and without any network or PostgreSQL access.
 */
class KnowledgeRetrievalTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/knowledge-retrieval-'.Str::lower(Str::random(8)));

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }

        $this->path = $this->directory.'/kb.sqlite';

        (new KnowledgeBaseBuilder)->build(base_path('database/knowledge/sources'), $this->path);

        config(['knowledge.sqlite_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_kb_t01_title_only_disease_name_returns_the_document(): void
    {
        $source = $this->source('ddc-disease-11');

        // The defect this task fixes: the name occurs in the title only.
        $this->assertStringContainsString('มือเท้าปาก', (string) $source['title']);
        $this->assertFalse(mb_stripos((string) $source['text'], 'มือเท้าปาก'));

        $results = $this->retriever()->search('มือเท้าปาก', 5);

        $this->assertNotEmpty($results);
        $this->assertSame('ddc-disease-11', $results[0]['document_id']);
        $this->assertSame('query', $results[0]['matched_by']);
        $this->assertSame((string) $source['title'], $results[0]['title']);
        $this->assertSame((string) $source['url'], $results[0]['url']);
    }

    public function test_kb_t02_spaced_thai_and_zero_width_characters_match_the_same_document(): void
    {
        $this->assertSame('มือเท้าปาก', ThaiText::normalise('มือ เท้า ปาก'));
        $this->assertSame('มือเท้าปาก', ThaiText::normalise("มือ\u{200B} เท้า   ปาก"));
        $this->assertSame('Hand, Foot and Mouth', ThaiText::normalise('Hand, Foot and Mouth'));
        $this->assertSame('PM2.5', ThaiText::normalise('PM 2.5'));

        $spaced = $this->retriever()->search('มือ เท้า ปาก', 5);
        $zeroWidth = $this->retriever()->search("มือ\u{200B}เท้าปาก", 5);

        $this->assertNotEmpty($spaced);
        $this->assertSame('ddc-disease-11', $spaced[0]['document_id']);
        $this->assertSame($spaced[0]['document_id'], $zeroWidth[0]['document_id']);
    }

    public function test_kb_t03_alias_expands_a_thai_colloquialism_and_is_marked(): void
    {
        $retriever = $this->retriever();

        // "ฉี่หนู" is now spelled directly in one source (the ThaiHealth leptospirosis article),
        // so it matches on the query itself; either leptospirosis document is a correct answer.
        $results = $retriever->search('ฉี่หนู', 2);

        $this->assertNotEmpty($results);
        $this->assertContains(
            $results[0]['document_id'],
            ['ddc-disease-16', 'thaihealth-lepto-symptoms'],
        );

        // The alias path is what carries a *symptom* description that shares no vocabulary with
        // the corpus - the case KB-2 was built for and the benchmark could not answer (Q-08).
        $symptomResults = $retriever->search('ปวดน่อง', 2);

        $this->assertNotEmpty($symptomResults, 'a symptom word must still reach the source through an alias');
        $this->assertSame('alias', $symptomResults[0]['matched_by']);
        $this->assertContains(
            $symptomResults[0]['document_id'],
            ['ddc-disease-16', 'thaihealth-lepto-symptoms'],
        );
        $this->assertContains($symptomResults[0]['matched_term'], ['เลปโตสไปโรซิส', 'leptospirosis', 'ฉี่หนู']);

        $exitCode = Artisan::call('knowledge:search', [
            'question' => 'ปวดน่อง',
            '--limit' => 2,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('alias', $output);
    }

    public function test_kb_t04_dust_and_pm25_return_both_thaihealth_sources(): void
    {
        $retriever = $this->retriever();

        foreach (['ฝุ่น', 'PM2.5', 'PM 2.5'] as $query) {
            $ids = array_column($retriever->search($query, 10), 'document_id');

            $this->assertContains('thaihealth-pm25-symptoms', $ids, 'query: '.$query);
            $this->assertContains('thaihealth-pm25-heart', $ids, 'query: '.$query);
        }
    }

    public function test_kb_t05_a_query_with_hits_is_never_widened_by_an_alias(): void
    {
        $results = $this->retriever()->search('ไข้เลือดออก', 5);

        $this->assertNotEmpty($results);
        $this->assertSame('ddc-disease-44', $results[0]['document_id']);
        $this->assertSame(['query'], array_values(array_unique(array_column($results, 'matched_by'))));
    }

    public function test_kb_t06_a_nonsense_query_still_returns_nothing(): void
    {
        $retriever = $this->retriever();

        $this->assertSame([], $retriever->search('ปลากระป๋องสีม่วง', 5));
        $this->assertSame([], $retriever->search('qzxwv-9876', 5));
    }

    public function test_kb_t07_the_build_stays_deterministic(): void
    {
        $builder = new KnowledgeBaseBuilder;
        $firstPath = $this->directory.'/kb-first.sqlite';
        $secondPath = $this->directory.'/kb-second.sqlite';

        $first = $builder->build(base_path('database/knowledge/sources'), $firstPath);
        $second = $builder->build(base_path('database/knowledge/sources'), $secondPath);

        $this->assertSame($first['documents'], $second['documents']);
        $this->assertSame($first['chunks'], $second['chunks']);
        $this->assertSame($first['skipped'], $second['skipped']);

        $firstHit = (new KnowledgeRetriever($firstPath))->search('มือเท้าปาก', 2)[0] ?? null;
        $secondHit = (new KnowledgeRetriever($secondPath))->search('มือเท้าปาก', 2)[0] ?? null;

        $this->assertNotNull($firstHit);
        $this->assertNotNull($secondHit);
        $this->assertSame($firstHit['document_id'], $secondHit['document_id']);
        $this->assertSame($firstHit['title'], $secondHit['title']);
    }

    private function retriever(): KnowledgeRetriever
    {
        return new KnowledgeRetriever($this->path);
    }

    /**
     * @return array<string, mixed>
     */
    private function source(string $id): array
    {
        $json = (string) file_get_contents(base_path('database/knowledge/sources/'.$id.'.json'));

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
