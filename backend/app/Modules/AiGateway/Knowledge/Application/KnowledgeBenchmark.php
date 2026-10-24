<?php

namespace App\Modules\AiGateway\Knowledge\Application;

use App\Modules\AiGateway\Knowledge\Domain\KnowledgeUnavailableException;
use RuntimeException;

/**
 * Measures the lexical retriever against a fixed set of real Thai questions
 * (TASK KB-3).
 *
 * The benchmark answers one question that `knowledge:search` cannot: "does the
 * retriever find an acceptable source for the questions a family caregiver
 * actually types?" It runs every question through the same `KnowledgeRetriever`
 * the API uses, in-process, and reports hit@1, hit@3 and MRR. It never builds,
 * writes or calls a provider.
 *
 * A question may accept more than one document (`expected` lists all fair
 * answers), because a symptom question can legitimately be answered by either
 * of two public articles. The first acceptable document in the ranked list
 * determines the rank; documents below it are ignored for that question.
 */
final class KnowledgeBenchmark
{
    /** A retrieval regression below these floors fails the command and the test. */
    public const MIN_HIT_AT_1 = 0.75;

    public const MIN_HIT_AT_3 = 0.95;

    public const MIN_MRR = 0.80;

    public function __construct(private readonly KnowledgeRetriever $retriever) {}

    public function path(): string
    {
        return (string) config(
            'knowledge.benchmark_path',
            database_path('knowledge/benchmark/queries.json'),
        );
    }

    /**
     * Reads and validates the benchmark set. Validation is deliberately strict:
     * a typo in an id would otherwise look like a retrieval miss.
     *
     * @return list<array{id: string, question: string, expected: list<string>}>
     */
    public function queries(?string $path = null): array
    {
        $path = $path ?? $this->path();

        if (! is_file($path)) {
            throw new RuntimeException('Benchmark query file not found: '.$path);
        }

        $json = file_get_contents($path);

        if (! is_string($json) || trim($json) === '') {
            throw new RuntimeException('Benchmark query file is empty: '.$path);
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Benchmark query file is not valid JSON: '.$path, 0, $exception);
        }

        if (! is_array($decoded) || $decoded === []) {
            throw new RuntimeException('Benchmark query file must contain a non-empty list: '.$path);
        }

        $queries = [];
        $ids = [];

        foreach ($decoded as $index => $query) {
            $label = 'query #'.($index + 1);

            if (! is_array($query)) {
                throw new RuntimeException($label.': must be an object');
            }

            $id = is_string($query['id'] ?? null) ? trim($query['id']) : '';
            $question = is_string($query['question'] ?? null) ? trim($query['question']) : '';
            $expected = $query['expected'] ?? null;

            if ($id === '') {
                throw new RuntimeException($label.': id is required');
            }

            if (isset($ids[$id])) {
                throw new RuntimeException($label.': duplicate id '.$id);
            }

            if ($question === '') {
                throw new RuntimeException($id.': question is required');
            }

            if (! is_array($expected) || $expected === []) {
                throw new RuntimeException($id.': expected must be a non-empty list of source ids');
            }

            $cleanExpected = [];

            foreach ($expected as $sourceId) {
                if (! is_string($sourceId) || trim($sourceId) === '') {
                    throw new RuntimeException($id.': every expected id must be a non-empty string');
                }

                $cleanExpected[] = trim($sourceId);
            }

            $ids[$id] = true;
            $queries[] = [
                'id' => $id,
                'question' => $question,
                'expected' => array_values(array_unique($cleanExpected)),
            ];
        }

        return $queries;
    }

    /**
     * Corpus size, read from the built SQLite file. The command reports these so
     * the decision document can be tied to a corpus, not just a percentage.
     *
     * @return array{documents: int, chunks: int, built_at: ?string, path: string, bytes: int}
     */
    public function corpus(): array
    {
        return $this->retriever->stats();
    }

    /**
     * @return list<string>
     */
    public function corpusDocumentIds(): array
    {
        return $this->retriever->documentIds();
    }

    /**
     * Source ids named by the benchmark that the built corpus does not contain.
     *
     * @param  list<array{id: string, question: string, expected: list<string>}>|null  $queries
     * @return list<string>
     */
    public function missingExpectedIds(?array $queries = null): array
    {
        $queries = $queries ?? $this->queries();
        $available = array_fill_keys($this->corpusDocumentIds(), true);
        $missing = [];

        foreach ($queries as $query) {
            foreach ($query['expected'] as $id) {
                if (! isset($available[$id])) {
                    $missing[$id] = true;
                }
            }
        }

        return array_keys($missing);
    }

    /**
     * Runs every question and returns the per-question rows plus the summary.
     *
     * @return array{
     *     corpus: array{documents: int, chunks: int, built_at: ?string, path: string, bytes: int},
     *     benchmark: string,
     *     limit: int,
     *     total: int,
     *     hits: int,
     *     misses: int,
     *     missed_at_3: int,
     *     hit_at_1: float,
     *     hit_at_3: float,
     *     mrr: float,
     *     floors: array{hit_at_1: float, hit_at_3: float, mrr: float},
     *     pass: bool,
     *     alias_hits: int,
     *     query_hits: int,
     *     results: list<array{
     *         id: string,
     *         question: string,
     *         expected: list<string>,
     *         top_1: ?string,
     *         top_1_title: ?string,
     *         hit: bool,
     *         rank: int,
     *         matched_by: ?string
     *     }>
     * }
     */
    public function run(?int $limit = null, ?string $path = null): array
    {
        if (! $this->retriever->isAvailable()) {
            throw new KnowledgeUnavailableException(
                'Knowledge base file is missing: '.$this->retriever->path(),
            );
        }

        $queries = $this->queries($path);
        $missing = $this->missingExpectedIds($queries);

        if ($missing !== []) {
            // A typo must fail loudly, not score as a retrieval miss.
            throw new RuntimeException(
                'Benchmark references source ids that are not in the corpus: '.implode(', ', $missing),
            );
        }

        $limit = max(3, min(20, $limit ?? (int) config('knowledge.top_k', 5)));
        $results = [];
        $hitAtOne = 0;
        $hitAtThree = 0;
        $hits = 0;
        $reciprocalRank = 0.0;
        $aliasHits = 0;
        $queryHits = 0;

        foreach ($queries as $query) {
            $rows = $this->retriever->search($query['question'], $limit);
            $rank = 0;
            $matchedBy = $rows[0]['matched_by'] ?? null;
            $topOne = $rows[0]['document_id'] ?? null;
            $topOneTitle = $rows[0]['title'] ?? null;

            foreach ($rows as $index => $row) {
                if (in_array($row['document_id'], $query['expected'], true)) {
                    $rank = $index + 1;
                    $matchedBy = $row['matched_by'];
                    break;
                }
            }

            if ($rank > 0) {
                $hits++;
                $reciprocalRank += 1 / $rank;

                if ($rank === 1) {
                    $hitAtOne++;
                }

                if ($rank <= 3) {
                    $hitAtThree++;
                }

                if ($matchedBy === 'alias') {
                    $aliasHits++;
                } else {
                    $queryHits++;
                }
            }

            $results[] = [
                'id' => $query['id'],
                'question' => $query['question'],
                'expected' => $query['expected'],
                'top_1' => $topOne,
                'top_1_title' => $topOneTitle,
                'hit' => $rank > 0,
                'rank' => $rank,
                'matched_by' => $matchedBy,
            ];
        }

        $total = count($queries);
        $hitAtOneRate = $total > 0 ? $hitAtOne / $total : 0.0;
        $hitAtThreeRate = $total > 0 ? $hitAtThree / $total : 0.0;
        $mrr = $total > 0 ? $reciprocalRank / $total : 0.0;
        $pass = $hitAtOneRate >= self::MIN_HIT_AT_1
            && $hitAtThreeRate >= self::MIN_HIT_AT_3
            && $mrr >= self::MIN_MRR;

        return [
            'corpus' => $this->corpus(),
            'benchmark' => $path ?? $this->path(),
            'limit' => $limit,
            'total' => $total,
            'hits' => $hits,
            'misses' => $total - $hits,
            'missed_at_3' => $total - $hitAtThree,
            'hit_at_1' => $hitAtOneRate,
            'hit_at_3' => $hitAtThreeRate,
            'mrr' => $mrr,
            'floors' => [
                'hit_at_1' => self::MIN_HIT_AT_1,
                'hit_at_3' => self::MIN_HIT_AT_3,
                'mrr' => self::MIN_MRR,
            ],
            'pass' => $pass,
            'alias_hits' => $aliasHits,
            'query_hits' => $queryHits,
            'results' => $results,
        ];
    }
}
