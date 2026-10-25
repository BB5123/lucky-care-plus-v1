<?php

namespace App\Console\Commands;

use App\Modules\AiGateway\Knowledge\Application\KnowledgeReviewPack;
use Illuminate\Console\Command;
use Throwable;

/**
 * Writes the clinician review pack (TASK REVIEW-PACK).
 *
 * The pack is a generated Markdown document: one section per knowledge source
 * with provenance, the current review decision, a bounded excerpt of every
 * section and the four checks a Thai clinician makes. The command is the only
 * writer; the file says so and gives the command to regenerate it.
 */
final class KnowledgeReviewPackCommand extends Command
{
    protected $signature = 'knowledge:review-pack
        {--output= : Output file (default: docs/KNOWLEDGE-REVIEW-PACK.md under backend)}
        {--status=unreviewed : unreviewed|reviewed|all}';

    protected $description = 'Write a review pack for the health knowledge sources';

    public function handle(KnowledgeReviewPack $pack): int
    {
        $status = strtolower(trim((string) $this->option('status')));

        if (! in_array($status, ['unreviewed', 'reviewed', 'all'], true)) {
            $this->error('--status must be unreviewed|reviewed|all');

            return self::FAILURE;
        }

        $output = trim((string) $this->option('output'));

        if ($output === '') {
            $output = 'docs/KNOWLEDGE-REVIEW-PACK.md';
        }

        $path = $this->absolutePath($output) ? $output : base_path($output);

        try {
            $result = $pack->generate($status);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('Cannot create the output directory: '.$directory);

            return self::FAILURE;
        }

        $temporary = $path.'.tmp';

        try {
            $written = file_put_contents($temporary, $result['contents'], LOCK_EX);
        } catch (Throwable $exception) {
            @unlink($temporary);
            $this->error('Cannot write the review pack: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($written === false) {
            @unlink($temporary);
            $this->error('Cannot write the review pack: '.$path);

            return self::FAILURE;
        }

        $renamed = false;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($temporary, $path)) {
                $renamed = true;
                break;
            }

            usleep(100_000);
        }

        if (! $renamed) {
            @unlink($temporary);
            $this->error('Cannot move the review pack into place: '.$path);

            return self::FAILURE;
        }

        $this->info('Review pack written: '.$path);
        $this->line('Selected '.$result['selected'].' of '.$result['total'].' sources ('.$status.'); '
            .$result['unreviewed'].' unreviewed in the corpus.');

        return self::SUCCESS;
    }

    private function absolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\/\\\\]|[\/\\\\]{2}|\/)/', $path) === 1;
    }
}
