<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeBaseBuilder;
use Illuminate\Console\Command;

/**
 * Validates the JSON knowledge sources before they are built (TASK G4-KB).
 *
 * Runs without the network: it checks ids, URLs, hosts, categories, review
 * status and text length so a bad source cannot reach the corpus.
 */
final class KnowledgeValidateCommand extends Command
{
    protected $signature = 'knowledge:validate
        {--source= : Source directory (default: database/knowledge/sources)}';

    protected $description = 'Validate the health knowledge JSON sources';

    public function handle(KnowledgeBaseBuilder $builder): int
    {
        $source = (string) ($this->option('source') ?: config('knowledge.sources_path'));

        if (! is_dir($source)) {
            $this->error('Source directory does not exist: '.$source);

            return self::FAILURE;
        }

        $sources = $builder->sources($source);
        $errors = $builder->validateSources($sources);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $published = 0;
        $reviewed = 0;

        foreach ($sources as $item) {
            $published++;
            $reviewed += ($item['review_status'] ?? '') === 'reviewed' ? 1 : 0;
        }

        $this->info('Sources valid: '.$published.' document(s), '.$reviewed.' reviewed, '
            .($published - $reviewed).' unreviewed.');

        return self::SUCCESS;
    }
}
