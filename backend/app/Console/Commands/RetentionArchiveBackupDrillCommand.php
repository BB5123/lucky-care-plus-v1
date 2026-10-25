<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Verifies an archive of retention bundles produced by
 * `retention:archive-backup`.
 *
 * It checks the outer `<archive>.sha256`, reads `manifest.json`, and re-hashes
 * every bundle entry against the hash recorded when the archive was written. A
 * backup that cannot be verified is only a hope - same rule as the documents
 * and corpus drills.
 */
final class RetentionArchiveBackupDrillCommand extends Command
{
    protected $signature = 'retention:archive-drill-backup
        {archive : Path to the zip produced by retention:archive-backup}
        {--json : Machine-readable output}';

    protected $description = 'Verify a retention bundle archive (outer checksum + every manifest entry)';

    public function handle(): int
    {
        $archive = (string) $this->argument('archive');

        if (! is_file($archive)) {
            $this->error('The archive does not exist.');

            return self::FAILURE;
        }

        $problems = [];
        $bundles = 0;
        $shaVerified = false;

        try {
            $shaFile = $archive.'.sha256';

            if (! is_file($shaFile)) {
                $problems[] = 'the checksum file is missing';
            } else {
                $recorded = trim((string) file_get_contents($shaFile));
                $expected = strtok($recorded, ' ');
                $actual = (string) hash_file('sha256', $archive);
                $shaVerified = is_string($expected) && hash_equals($expected, $actual);

                if (! $shaVerified) {
                    $problems[] = 'the archive checksum does not match the .sha256 file';
                }
            }

            $zip = new ZipArchive;

            if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Could not open the archive for reading.');
            }

            try {
                $raw = $zip->getFromName('manifest.json');

                if (! is_string($raw)) {
                    $problems[] = 'the archive has no manifest.json';
                } else {
                    $manifest = json_decode($raw, true);

                    if (! is_array($manifest) || ! is_array($manifest['bundles'] ?? null)) {
                        $problems[] = 'the archive manifest is not recognisable';
                    } else {
                        foreach ($manifest['bundles'] as $entry) {
                            if (! is_array($entry) || ! isset($entry['entry'], $entry['sha256'])) {
                                $problems[] = 'the archive manifest contains an entry without a path or hash';

                                continue;
                            }

                            $content = $zip->getFromName((string) $entry['entry']);

                            if (! is_string($content)) {
                                $problems[] = 'a bundle listed in the manifest is missing from the archive';

                                continue;
                            }

                            $bundles++;

                            if (! hash_equals((string) $entry['sha256'], hash('sha256', $content))) {
                                $problems[] = 'a bundle does not match the hash recorded in the manifest';
                            }
                        }
                    }
                }
            } finally {
                $zip->close();
            }
        } catch (Throwable $exception) {
            $this->error('Archive drill failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $ok = $problems === [];

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'archive' => $archive,
                'checksum_verified' => $shaVerified,
                'bundles' => $bundles,
                'problems' => $problems,
            ], JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Archive: '.$archive);
            $this->line('Outer checksum: '.($shaVerified ? 'ok' : 'NO'));
            $this->line('Bundles verified: '.$bundles);

            foreach ($problems as $problem) {
                $this->error($problem);
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
