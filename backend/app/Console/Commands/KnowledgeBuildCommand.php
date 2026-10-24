<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeBaseBuilder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Builds the SQLite knowledge base from `database/knowledge/sources` (TASK G4-KB).
 *
 * The output is deterministic and replaces the previous file atomically, so a
 * deployment can rebuild it from the reviewed JSON sources or restore a
 * prebuilt copy. Nothing is written to PostgreSQL.
 */
final class KnowledgeBuildCommand extends Command
{
    protected $signature = 'knowledge:build
        {--source= : Source directory (default: database/knowledge/sources)}
        {--target= : SQLite output path (default: database/knowledge/health_kb.sqlite)}';

    protected $description = 'Build the SQLite FTS5 health knowledge base from JSON sources';

    public function handle(KnowledgeBaseBuilder $builder): int
    {
        $source = (string) ($this->option('source') ?: config('knowledge.sources_path'));
        $target = (string) ($this->option('target') ?: config('knowledge.sqlite_path'));

        if (! is_dir($source)) {
            $this->error('Source directory does not exist: '.$source);

            return self::FAILURE;
        }

        try {
            $stats = $builder->build($source, $target);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Knowledge base built: '.$target);
        $this->line('documents: '.$stats['documents'].', chunks: '.$stats['chunks']
            .', skipped: '.$stats['skipped']);
        $this->line('built at: '.$stats['built_at']);

        return self::SUCCESS;
    }
}
