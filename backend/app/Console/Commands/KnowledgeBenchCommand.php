<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeBenchmark;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeUnavailableException;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Measures retrieval quality against the benchmark questions (TASK KB-3).
 *
 * It is the repeatable answer to "does the RAG find the right source for a real
 * Thai question?" — `knowledge:search` only shows one query at a time. The
 * command reads the same built SQLite file the API uses, performs no network
 * call, rebuilds nothing, and fails when the measured floors are not met.
 */
final class KnowledgeBenchCommand extends Command
{
    protected $signature = 'knowledge:bench
        {--json : Print a machine-readable object instead of a table}
        {--limit= : Results to retrieve per question (default: knowledge.top_k, minimum 3)}';

    protected $description = 'Benchmark Thai knowledge retrieval (hit@1, hit@3, MRR) against the built corpus';

    public function handle(KnowledgeBenchmark $benchmark): int
    {
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? max(3, min(20, (int) $limit)) : null;

        try {
            $report = $benchmark->run($limit);
        } catch (KnowledgeUnavailableException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $payload = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            $this->line(is_string($payload) ? $payload : '{}');

            return $report['pass'] ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf(
            'Knowledge retrieval benchmark — corpus %d documents / %d chunks (built %s), %d questions, top-%d.',
            $report['corpus']['documents'],
            $report['corpus']['chunks'],
            $report['corpus']['built_at'] ?? 'unknown',
            $report['total'],
            $report['limit'],
        ));

        $rows = [];

        foreach ($report['results'] as $result) {
            $rows[] = [
                $result['id'],
                mb_substr($result['question'], 0, 46).(mb_strlen($result['question']) > 46 ? '…' : ''),
                $result['top_1'] ?? '—',
                $result['rank'] > 0 ? (string) $result['rank'] : 'miss',
                $result['hit'] ? 'hit' : 'miss',
                $result['matched_by'] ?? '—',
            ];
        }

        $this->table(['ID', 'Question', 'Top-1 source', 'Rank', 'Result', 'Matched by'], $rows);

        $this->line(sprintf(
            'hit@1 %.3f (floor %.2f) · hit@3 %.3f (floor %.2f) · MRR %.3f (floor %.2f) · '
            .'hits %d/%d · missed top-3 %d · not found %d',
            $report['hit_at_1'],
            $report['floors']['hit_at_1'],
            $report['hit_at_3'],
            $report['floors']['hit_at_3'],
            $report['mrr'],
            $report['floors']['mrr'],
            $report['hits'],
            $report['total'],
            $report['missed_at_3'],
            $report['misses'],
        ));
        $this->line(sprintf(
            'Matched by: query %d · alias %d.',
            $report['query_hits'],
            $report['alias_hits'],
        ));

        if ($report['missed_at_3'] > 0) {
            $this->warn('Questions whose acceptable source was not in the top 3:');

            foreach ($report['results'] as $result) {
                if ($result['rank'] > 0 && $result['rank'] <= 3) {
                    continue;
                }

                $this->line(sprintf(
                    '  - %s rank=%s top-1=%s — %s',
                    $result['id'],
                    $result['rank'] > 0 ? (string) $result['rank'] : 'miss',
                    $result['top_1'] ?? '—',
                    $result['question'],
                ));
            }
        }

        if (! $report['pass']) {
            $this->error('Retrieval quality is below the floors. See backend/docs/RAG-VECTOR-OPTIONS.md.');

            return self::FAILURE;
        }

        $this->info('Retrieval quality meets every floor.');

        return self::SUCCESS;
    }
}
