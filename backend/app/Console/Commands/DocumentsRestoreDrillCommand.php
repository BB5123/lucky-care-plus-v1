<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use ZipArchive;

/**
 * Proves a documents archive is readable, complete and unchanged (gap 4).
 *
 * The nightly pattern for the other two halves is "restore the artifact you
 * actually kept", not a fresh dump. This command re-opens the zip from
 * `documents:backup`, checks every file against the manifest hash and size,
 * and fails when the manifest itself says a metadata row had no object.
 *
 * It extracts nothing to disk: reading the entry is enough to prove the archive
 * is not corrupt, and a temp extraction would only add a way to leak a
 * document into /tmp.
 */
final class DocumentsRestoreDrillCommand extends Command
{
    protected $signature = 'documents:restore-drill
        {archive : Path to the archive produced by documents:backup}
        {--json : Machine-readable summary}';

    protected $description = 'Verify a documents archive against its manifest (readable, hashes, completeness)';

    public function handle(): int
    {
        $path = (string) $this->argument('archive');

        if (! is_file($path)) {
            $this->error('The archive does not exist.');

            return self::FAILURE;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            $this->error('The archive could not be opened.');

            return self::FAILURE;
        }

        try {
            $summary = $this->drill($zip, $path);
        } catch (Throwable $exception) {
            $this->error('Document restore drill failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        } finally {
            $zip->close();
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Verified', 'Recorded missing', 'Failures'],
                [[$summary['verified'], count($summary['recorded_missing']), count($summary['failures'])]],
            );

            if ($summary['sha256_matches'] === false) {
                $this->warn('The .sha256 file next to the archive does not match its current bytes.');
            }
        }

        if ($summary['failures'] !== []) {
            $this->error(count($summary['failures']).' file(s) failed verification.');

            return self::FAILURE;
        }

        if ($summary['recorded_missing'] !== []) {
            $this->error(
                'The manifest records '.count($summary['recorded_missing'])
                .' metadata row(s) without an object: this archive is incomplete.'
            );

            return self::FAILURE;
        }

        $this->info('Document archive is readable, complete and unchanged.');

        return self::SUCCESS;
    }

    /**
     * @return array{verified: int, recorded_missing: list<string>, failures: list<string>, sha256_matches: bool}
     */
    private function drill(ZipArchive $zip, string $path): array
    {
        $manifestJson = $zip->getFromName('manifest.json');

        if (! is_string($manifestJson)) {
            throw new \RuntimeException('The archive has no manifest.json.');
        }

        $manifest = json_decode($manifestJson, true);

        if (! is_array($manifest) || ! is_array($manifest['files'] ?? null)) {
            throw new \RuntimeException('The manifest is not readable.');
        }

        $failures = [];
        $verified = 0;

        foreach ($manifest['files'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                $failures[] = 'malformed manifest entry';

                continue;
            }

            $content = $zip->getFromName($entry['path']);

            if (! is_string($content)) {
                $failures[] = $entry['path'].': missing from the archive';

                continue;
            }

            if (strlen($content) !== (int) ($entry['size'] ?? -1)) {
                $failures[] = $entry['path'].': size mismatch';

                continue;
            }

            if (! hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $content))) {
                $failures[] = $entry['path'].': hash mismatch';

                continue;
            }

            $verified++;
        }

        $recordedMissing = is_array($manifest['missing'] ?? null)
            ? array_values(array_filter($manifest['missing'], 'is_string'))
            : [];

        $checksumFile = $path.'.sha256';
        $sha256Matches = true;

        if (is_file($checksumFile)) {
            $expected = trim((string) strtok((string) file_get_contents($checksumFile), ' '));
            $sha256Matches = $expected !== '' && hash_equals($expected, (string) hash_file('sha256', $path));
        }

        return [
            'verified' => $verified,
            'recorded_missing' => $recordedMissing,
            'failures' => $failures,
            'sha256_matches' => $sha256Matches,
        ];
    }
}
