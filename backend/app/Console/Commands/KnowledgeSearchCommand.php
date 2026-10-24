<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeRetriever;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeUnavailableException;
use Illuminate\Console\Command;

/**
 * Inspects the health knowledge retrieval without calling an AI provider.
 *
 * Useful before enabling the answer endpoint and when checking a freshly
 * fetched source: it shows exactly which documents would be cited.
 */
final class KnowledgeSearchCommand extends Command
{
    protected $signature = 'knowledge:search
        {question : Thai question or keywords}
        {--limit=5 : Number of chunks to show}';

    protected $description = 'Search the SQLite health knowledge base (no AI call)';

    public function handle(KnowledgeRetriever $retriever): int
    {
        $question = trim((string) $this->argument('question'));

        if ($question === '') {
            $this->error('The question is empty.');

            return self::FAILURE;
        }

        if (! $retriever->isAvailable()) {
            $this->error('Knowledge base not found: '.$retriever->path());
            $this->line('Run: php artisan knowledge:build');

            return self::FAILURE;
        }

        try {
            $results = $retriever->search($question, (int) $this->option('limit'));
        } catch (KnowledgeUnavailableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->warn('No matching document.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($results as $index => $result) {
            $excerpt = preg_replace('/\s+/u', ' ', $result['text']) ?? $result['text'];
            $matchedBy = ($result['matched_by'] ?? 'query') === 'alias'
                ? 'alias: '.($result['matched_term'] ?? '')
                : 'query';
            $rows[] = [
                $index + 1,
                $result['document_id'],
                $result['title'],
                $matchedBy,
                number_format($result['score'], 6),
                mb_substr($excerpt, 0, 70).'…',
            ];
        }

        $this->table(['#', 'Document', 'Title', 'Matched by', 'Score', 'Excerpt'], $rows);

        return self::SUCCESS;
    }
}
