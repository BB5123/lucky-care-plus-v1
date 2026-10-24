<?php

namespace Tests\Feature\AiGateway;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeBenchmark;
use App\Modules\AiGateway\Knowledge\Application\KnowledgeRetriever;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * TASK KB-3 — the retrieval benchmark is a gate, not a report.
 *
 * These tests run against the committed corpus and the committed question set,
 * so a retrieval regression (or a stale expected id) fails CI instead of waiting
 * for an operator to notice. They never rebuild the SQLite file.
 */
class KnowledgeBenchTest extends TestCase
{
    private string $corpus;

    private string $benchmark;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corpus = base_path('database/knowledge/health_kb.sqlite');
        $this->benchmark = base_path('database/knowledge/benchmark/queries.json');

        config([
            'knowledge.sqlite_path' => $this->corpus,
            'knowledge.benchmark_path' => $this->benchmark,
        ]);
    }

    public function test_the_benchmark_meets_the_retrieval_floors(): void
    {
        $this->assertFileExists($this->corpus);
        $this->assertFileExists($this->benchmark);

        $exitCode = Artisan::call('knowledge:bench', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($report);
        $this->assertGreaterThanOrEqual(24, $report['total']);
        $this->assertGreaterThan(0, $report['corpus']['documents']);
        $this->assertGreaterThan(0, $report['corpus']['chunks']);
        $this->assertGreaterThanOrEqual(
            KnowledgeBenchmark::MIN_HIT_AT_1,
            $report['hit_at_1'],
            'hit@1 regressed: '.$report['hit_at_1'],
        );
        $this->assertGreaterThanOrEqual(
            KnowledgeBenchmark::MIN_HIT_AT_3,
            $report['hit_at_3'],
            'hit@3 regressed: '.$report['hit_at_3'],
        );
        $this->assertGreaterThanOrEqual(
            KnowledgeBenchmark::MIN_MRR,
            $report['mrr'],
            'MRR regressed: '.$report['mrr'],
        );
        $this->assertTrue($report['pass']);

        foreach ($report['results'] as $result) {
            $this->assertNotEmpty($result['question'], $result['id']);
            $this->assertNotEmpty($result['expected'], $result['id']);
        }
    }

    public function test_every_expected_source_id_exists_in_the_corpus(): void
    {
        $this->assertFileExists($this->corpus);

        $runner = new KnowledgeBenchmark(new KnowledgeRetriever($this->corpus));
        $queries = $runner->queries($this->benchmark);
        $missing = $runner->missingExpectedIds($queries);

        $this->assertNotEmpty($runner->corpusDocumentIds(), 'The corpus has no documents.');
        $this->assertSame([], $missing, 'Benchmark references unknown source ids: '.implode(', ', $missing));
    }
}
