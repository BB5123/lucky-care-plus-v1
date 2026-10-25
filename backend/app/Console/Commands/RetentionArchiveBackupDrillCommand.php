<?php

namespace App\Console\Commands;

use App\Modules\Retention\Services\ArchiveBundleRegistry;
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
        {--check-db : Also require every bundle in the database to be present in the archive}
        {--json : Machine-readable output}';

    protected $description = 'Verify a retention bundle archive (outer checksum + every manifest entry)';

    public function handle(ArchiveBundleRegistry $registry): int
    {
        $archive = (string) $this->argument('archive');

        if (! is_file($archive)) {
            $this->error('The archive does not exist.');

            return self::FAILURE;
        }

        $problems = [];
        $bundles = 0;
        $shaVerified = false;
        $entries = [];
        $checkedAgainstDb = false;

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
                            $entries[] = $entry;

                            if (! hash_equals((string) $entry['sha256'], hash('sha256', $content))) {
                                $problems[] = 'a bundle does not match the hash recorded in the manifest';
                            }
                        }
                    }
                }
            } finally {
                $zip->close();
            }

            if ((bool) $this->option('check-db')) {
                $checkedAgainstDb = true;
                $byId = [];

                foreach ($entries as $entry) {
                    if (isset($entry['manifest_id'])) {
                        $byId[(string) $entry['manifest_id']] = $entry;
                    }
                }

                // The archive can be internally perfect and still be stale: a
                // workspace archived after the backup is not in it. This is the
                // check the nightly workflow runs on the uploaded copy.
                foreach ($registry->all() as $dbManifest) {
                    $id = (string) $dbManifest->id;

                    if (! isset($byId[$id])) {
                        $problems[] = "a bundle in the database is missing from the archive ({$id})";

                        continue;
                    }

                    if (! hash_equals((string) $dbManifest->archive_sha256, (string) $byId[$id]['sha256'])) {
                        $problems[] = "bundle {$id} does not match the database manifest hash";
                    }
                }
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
                'checked_against_db' => $checkedAgainstDb,
                'problems' => $problems,
            ], JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Archive: '.$archive);
            $this->line('Outer checksum: '.($shaVerified ? 'ok' : 'NO'));
            $this->line('Bundles verified: '.$bundles);
            $this->line('Checked against the database: '.($checkedAgainstDb ? 'yes' : 'no'));

            foreach ($problems as $problem) {
                $this->error($problem);
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
