<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use PDO;
use PharData;
use RuntimeException;
use Throwable;

/**
 * Restore drill for the mutable knowledge corpus (TASK G).
 *
 * `knowledge:backup` produces an archive; this command proves the archive can
 * actually be restored *without touching the live corpus*. It extracts into a
 * throw-away temporary directory and then checks, in order, the things that
 * would make an answer wrong after a restore:
 *
 *   1. every `sources/*.json` parses and carries id/title/url/text,
 *   2. source ids are unique (a merge accident),
 *   3. the SQLite index opens and `PRAGMA integrity_check` returns `ok`,
 *   4. the index has rows and its `meta` build record agrees with the tables,
 *   5. every reviewed source kept its `reviewed_by`/`reviewed_at`
 *      (the whole reason this drill exists - the review decisions are the
 *      mutable state that lives in the volume),
 *   6. the source files on disk are the same set the index was built from.
 *
 * It stops at the first failure, exits non-zero, and always removes the
 * temporary directory. It never writes to `database/knowledge`.
 */
final class KnowledgeRestoreDrillCommand extends Command
{
    protected $signature = 'knowledge:restore-drill
        {--archive= : The knowledge-<timestamp>.tar.gz archive to prove}
        {--json : Emit a machine-readable result instead of a table}';

    protected $description = 'Extract a knowledge corpus archive and prove it is restorable';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $archive = trim((string) $this->option('archive'));

        if ($archive === '') {
            $this->error('knowledge:restore-drill failed: --archive is required.');

            return self::FAILURE;
        }

        if (! is_file($archive)) {
            $this->error('knowledge:restore-drill failed: archive does not exist: '.$archive);

            return self::FAILURE;
        }

        if (filesize($archive) === 0) {
            $this->error('knowledge:restore-drill failed: archive is empty: '.$archive);

            return self::FAILURE;
        }

        $directory = sys_get_temp_dir().'/lc-knowledge-drill-'.bin2hex(random_bytes(6));

        if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('knowledge:restore-drill failed: cannot create a temporary directory.');

            return self::FAILURE;
        }

        try {
            $temporary = new PharData($archive);

            if (! $temporary->extractTo($directory)) {
                $this->error('knowledge:restore-drill failed: the archive could not be extracted.');

                return self::FAILURE;
            }

            unset($temporary);

            $results = [];
            $state = ['ids' => [], 'sources' => [], 'sqlite' => null];
            $failed = false;

            foreach ($this->checks($directory, $state) as $check) {
                try {
                    $detail = ($check['run'])();
                    $results[] = ['id' => $check['id'], 'name' => $check['name'], 'result' => 'ok', 'detail' => $detail];
                } catch (Throwable $exception) {
                    $results[] = [
                        'id' => $check['id'],
                        'name' => $check['name'],
                        'result' => 'fail',
                        'detail' => $exception->getMessage(),
                    ];
                    $failed = true;

                    break;
                }
            }
        } catch (Throwable $exception) {
            $this->error('knowledge:restore-drill failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->remove($directory);
        }

        if ($json) {
            $this->line((string) json_encode([
                'archive' => $archive,
                'passed' => ! $failed,
                'checks' => $results,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Check', 'What', 'Result', 'Detail'],
                array_map(
                    static fn (array $row): array => [$row['id'], $row['name'], $row['result'], $row['detail']],
                    $results,
                ),
            );
        }

        if ($failed) {
            $last = $results[count($results) - 1];
            $this->error('knowledge restore drill FAILED at `'.$last['id'].'`: '.$last['detail']);

            return self::FAILURE;
        }

        if (! $json) {
            $this->info('knowledge restore drill passed: '.$archive);
        }

        return self::SUCCESS;
    }

    /**
     * Ordered checks. Each closure returns the detail string on success and
     * throws with a named reason on failure; the loop stops at the first throw.
     *
     * @param  array{ids: list<string>, sources: list<array<string, mixed>>, sqlite: ?PDO}  $state
     * @return list<array{id: string, name: string, run: callable(): string}>
     */
    private function checks(string $directory, array &$state): array
    {
        return [
            [
                'id' => 'source-json',
                'name' => 'Every source parses as JSON with id/title/url/text',
                'run' => function () use ($directory, &$state): string {
                    $files = glob($directory.'/sources/*.json') ?: [];

                    if ($files === []) {
                        throw new RuntimeException('No sources/*.json files were extracted.');
                    }

                    foreach ($files as $file) {
                        $label = basename($file);
                        $contents = file_get_contents($file);

                        if (! is_string($contents) || trim($contents) === '') {
                            throw new RuntimeException('The source '.$label.' is empty.');
                        }

                        try {
                            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                        } catch (JsonException $exception) {
                            throw new RuntimeException('The source '.$label.' is not valid JSON: '.$exception->getMessage());
                        }

                        if (! is_array($decoded)) {
                            throw new RuntimeException('The source '.$label.' does not decode to an object.');
                        }

                        foreach (['id', 'title', 'url', 'text'] as $key) {
                            if (! array_key_exists($key, $decoded)
                                || ! is_string($decoded[$key])
                                || trim($decoded[$key]) === '') {
                                throw new RuntimeException('The source '.$label.' is missing the required key `'.$key.'`.');
                            }
                        }

                        $state['ids'][] = (string) $decoded['id'];
                        $state['sources'][] = $decoded;
                    }

                    return count($files).' source file(s) parsed';
                },
            ],
            [
                'id' => 'unique-ids',
                'name' => 'Source ids are unique',
                'run' => function () use (&$state): string {
                    $duplicates = array_keys(array_filter(
                        array_count_values($state['ids']),
                        static fn (int $count): bool => $count > 1,
                    ));

                    if ($duplicates !== []) {
                        throw new RuntimeException('Duplicate source id(s): '.implode(', ', $duplicates));
                    }

                    return count($state['ids']).' unique id(s)';
                },
            ],
            [
                'id' => 'sqlite-integrity',
                'name' => 'The SQLite index opens and passes integrity_check',
                'run' => function () use ($directory, &$state): string {
                    $files = glob($directory.'/*.sqlite') ?: [];

                    if ($files === []) {
                        throw new RuntimeException('No *.sqlite index was extracted.');
                    }

                    $path = $files[0];

                    if (! is_file($path) || filesize($path) <= 0) {
                        throw new RuntimeException('The SQLite index is empty: '.basename($path));
                    }

                    try {
                        $pdo = new PDO('sqlite:'.$path);
                        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
                    } catch (Throwable $exception) {
                        throw new RuntimeException('The SQLite index cannot be read: '.$exception->getMessage());
                    }

                    if ($integrity !== 'ok') {
                        throw new RuntimeException('integrity_check returned: '.(string) $integrity);
                    }

                    $tables = $pdo->query("select name from sqlite_master where type = 'table'")
                        ->fetchAll(PDO::FETCH_COLUMN);

                    foreach (['documents', 'chunks', 'meta'] as $table) {
                        if (! in_array($table, $tables, true)) {
                            throw new RuntimeException('The SQLite index has no `'.$table.'` table.');
                        }
                    }

                    $state['sqlite'] = $pdo;

                    return 'integrity_check ok, '.count($tables).' table(s)';
                },
            ],
            [
                'id' => 'index-counts',
                'name' => 'The index has rows and agrees with its meta build record',
                'run' => function () use (&$state): string {
                    $pdo = $state['sqlite'];

                    $documents = (int) $pdo->query('select count(*) from documents')->fetchColumn();
                    $chunks = (int) $pdo->query('select count(*) from chunks')->fetchColumn();

                    if ($documents <= 0) {
                        throw new RuntimeException('The documents table is empty.');
                    }

                    if ($chunks <= 0) {
                        throw new RuntimeException('The chunks table is empty.');
                    }

                    $meta = [];

                    foreach ($pdo->query('select key, value from meta') as $row) {
                        $meta[(string) $row[0]] = (string) $row[1];
                    }

                    foreach (['documents' => $documents, 'chunks' => $chunks] as $key => $actual) {
                        if (! array_key_exists($key, $meta)) {
                            throw new RuntimeException('The meta table has no `'.$key.'` build record.');
                        }

                        if ((int) $meta[$key] !== $actual) {
                            throw new RuntimeException('meta '.$key.'='.$meta[$key].' but the table holds '.$actual.' rows.');
                        }
                    }

                    return $documents.' documents, '.$chunks.' chunks (meta agrees)';
                },
            ],
            [
                'id' => 'review-fields',
                'name' => 'Every reviewed source kept reviewed_by/reviewed_at',
                'run' => function () use (&$state): string {
                    $missing = [];
                    $reviewed = 0;

                    foreach ($state['sources'] as $source) {
                        if (($source['review_status'] ?? '') !== 'reviewed') {
                            continue;
                        }

                        $reviewed++;

                        if (trim((string) ($source['reviewed_by'] ?? '')) === ''
                            || trim((string) ($source['reviewed_at'] ?? '')) === '') {
                            $missing[] = (string) ($source['id'] ?? 'unknown');
                        }
                    }

                    foreach ($state['sqlite']->query(
                        "select id, reviewed_by, reviewed_at from documents where review_status = 'reviewed'",
                    ) as $row) {
                        if (trim((string) $row['reviewed_by']) === '' || trim((string) $row['reviewed_at']) === '') {
                            $missing[] = 'index:'.$row['id'];
                        }
                    }

                    if ($missing !== []) {
                        throw new RuntimeException(
                            'The review decision was lost (reviewed_by/reviewed_at missing) for: '.implode(', ', $missing),
                        );
                    }

                    return $reviewed.' reviewed source(s) carry a reviewer';
                },
            ],
            [
                'id' => 'membership',
                'name' => 'The restored source set matches the indexed documents',
                'run' => function () use (&$state): string {
                    $sourceIds = $state['ids'];
                    $documentIds = $state['sqlite']->query('select id from documents')->fetchAll(PDO::FETCH_COLUMN);

                    sort($sourceIds);
                    sort($documentIds);

                    $sourceIds = array_map('strval', $sourceIds);
                    $documentIds = array_map('strval', $documentIds);

                    if ($sourceIds !== $documentIds) {
                        throw new RuntimeException(
                            'Source set differs from the index: missing='
                            .implode(', ', array_diff($documentIds, $sourceIds))
                            .' extra='
                            .implode(', ', array_diff($sourceIds, $documentIds)),
                        );
                    }

                    return count($sourceIds).' source id(s) match the index';
                },
            ],
        ];
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
