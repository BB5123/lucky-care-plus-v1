<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeSourceService;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeSourceInvalidException;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeSourceNotFoundException;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Marks a knowledge source reviewed (or unreviewed) — TASK G4-KB / CR-005.
 *
 * The answer path appends an "unreviewed" warning to every answer that uses a
 * source whose `review_status` is not `reviewed`. This command is the console
 * entry point; the platform-admin API calls the same
 * {@see KnowledgeSourceService} so the two paths cannot drift.
 */
final class KnowledgeReviewCommand extends Command
{
    protected $signature = 'knowledge:review
        {id : Source id (the file name without .json)}
        {--status=reviewed : reviewed|unreviewed}
        {--reviewer= : Who reviewed the content (required for reviewed)}
        {--build : Rebuild the SQLite corpus after the change}';

    protected $description = 'Mark a knowledge source reviewed/unreviewed and optionally rebuild';

    public function handle(KnowledgeSourceService $service): int
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

        $reviewer = trim((string) $this->option('reviewer'));

        if ($status === 'reviewed' && $reviewer === '') {
            $this->error('--reviewer is required when marking a source reviewed.');

            return self::FAILURE;
        }

        try {
            $outcome = $service->review($id, $reviewer, (bool) $this->option('build'), $status);
        } catch (KnowledgeSourceNotFoundException) {
            $this->error('Source not found: '.$service->pathFor($id));

            return self::FAILURE;
        } catch (KnowledgeSourceInvalidException $exception) {
            foreach ($exception->errors() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Updated: '.$service->pathFor($id).' → '.$status
            .($reviewer !== '' ? ' by '.$reviewer : ''));

        $stats = $outcome['stats'];

        if ($stats === null) {
            $this->line('Run `php artisan knowledge:build` to publish the change.');

            return self::SUCCESS;
        }

        $this->info('Knowledge base rebuilt: '.$stats['documents'].' documents, '
            .$stats['chunks'].' chunks.');

        return self::SUCCESS;
    }
}
