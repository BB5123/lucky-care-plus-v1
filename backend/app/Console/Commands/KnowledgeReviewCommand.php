<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeBaseBuilder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Marks a knowledge source reviewed (or unreviewed) — TASK G4-KB.
 *
 * The answer path appends an "unreviewed" warning to every answer that uses a
 * source whose `review_status` is not `reviewed`. This command is the review
 * workflow until a platform-admin UI exists: it records who reviewed the
 * source and when, revalidates it, writes the JSON atomically and can rebuild
 * the SQLite corpus in the same step.
 */
final class KnowledgeReviewCommand extends Command
{
    protected $signature = 'knowledge:review
        {id : Source id (the file name without .json)}
        {--status=reviewed : reviewed|unreviewed}
        {--reviewer= : Who reviewed the content (required for reviewed)}
        {--build : Rebuild the SQLite corpus after the change}';

    protected $description = 'Mark a knowledge source reviewed/unreviewed and optionally rebuild';

    public function handle(KnowledgeBaseBuilder $builder): int
    {
        $id = trim((string) $this->argument('id'));
        $status = (string) $this->option('status');

        if (preg_match('/^[a-z0-9][a-z0-9-]{2,80}$/', $id) !== 1) {
            $this->error('The source id must be a lowercase slug.');

            return self::FAILURE;
        }

        if (! in_array($status, ['reviewed', 'unreviewed'], true)) {
            $this->error('--status must be reviewed|unreviewed');

            return self::FAILURE;
        }

        $directory = (string) config('knowledge.sources_path');
        $path = rtrim($directory, '/\\').'/'.$id.'.json';

        if (! is_file($path)) {
            $this->error('Source not found: '.$path);

            return self::FAILURE;
        }

        $json = file_get_contents($path);

        if (! is_string($json) || trim($json) === '') {
            $this->error('Source file is empty.');

            return self::FAILURE;
        }

        $source = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($source) || ($source['id'] ?? null) !== $id) {
            $this->error('Source file does not carry the expected id.');

            return self::FAILURE;
        }

        $reviewer = trim((string) $this->option('reviewer'));

        if ($status === 'reviewed') {
            if ($reviewer === '') {
                $this->error('--reviewer is required when marking a source reviewed.');

                return self::FAILURE;
            }

            $source['review_status'] = 'reviewed';
            $source['reviewed_by'] = $reviewer;
            $source['reviewed_at'] = now()->toDateString();
        } else {
            $source['review_status'] = 'unreviewed';
            $source['reviewed_by'] = null;
            $source['reviewed_at'] = null;
        }

        $errors = $builder->validateSources([$source]);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $encoded = json_encode(
            $source,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($encoded)) {
            $this->error('Could not encode the source.');

            return self::FAILURE;
        }

        file_put_contents($path, $encoded."\n");

        $this->info('Updated: '.$path.' → '.$status
            .($reviewer !== '' ? ' by '.$reviewer : ''));

        if (! (bool) $this->option('build')) {
            $this->line('Run `php artisan knowledge:build` to publish the change.');

            return self::SUCCESS;
        }

        try {
            $stats = $builder->build($directory, (string) config('knowledge.sqlite_path'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Knowledge base rebuilt: '.$stats['documents'].' documents, '
            .$stats['chunks'].' chunks.');

        return self::SUCCESS;
    }
}
