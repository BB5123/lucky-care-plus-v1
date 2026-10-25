<?php

namespace App\Modules\AiGateway\Knowledge\Application;

use App\Modules\AiGateway\Knowledge\Domain\KnowledgeCorpusReadonlyException;
use App\Modules\AiGateway\Knowledge\Domain\ThaiText;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Builds the read-only SQLite knowledge base (TASK G4-KB).
 *
 * Sources are reviewable JSON files (`database/knowledge/sources/*.json`) with
 * title/publisher/url/text and a review status. The build is deterministic:
 * the same sources produce the same documents, chunks and keywords, so a fresh
 * installation can rebuild or restore the `.sqlite` file.
 *
 * SQLite FTS5 with the trigram tokenizer is used for retrieval: it handles Thai
 * substrings without an embedding model. The FTS row holds the document title
 * next to the chunk body so a disease name that appears only in the title is
 * findable, and both are passed through `ThaiText::normalise()` so a spaced
 * spelling and an unspaced query meet in the same token stream. The interface
 * is deliberately narrow so a Qdrant/pgvector retriever can replace it later.
 */
final class KnowledgeBaseBuilder
{
    private const SCHEMA_VERSION = 2;

    private readonly int $chunkChars;

    private readonly int $chunkOverlap;

    public function __construct(?int $chunkChars = null, ?int $chunkOverlap = null)
    {
        $this->chunkChars = max(200, $chunkChars ?? (int) config('knowledge.chunk_chars', 900));
        $this->chunkOverlap = max(0, min(
            $chunkOverlap ?? (int) config('knowledge.chunk_overlap', 150),
            (int) floor($this->chunkChars / 2),
        ));
    }

    /**
     * @return array{documents: int, chunks: int, skipped: int, built_at: string}
     */
    public function build(string $sourceDir, string $targetPath): array
    {
        $sources = $this->sources($sourceDir);
        $errors = $this->validateSources($sources);

        if ($errors !== []) {
            throw new RuntimeException('Knowledge sources are invalid: '.implode(' | ', $errors));
        }

        $directory = dirname($targetPath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new KnowledgeCorpusReadonlyException(
                'Cannot create the knowledge directory: '.$directory,
            );
        }

        $temporary = $targetPath.'.tmp';

        if (file_exists($temporary)) {
            unlink($temporary);
        }

        $pdo = new PDO('sqlite:'.$temporary);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = MEMORY');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(<<<'SQL'
            create table documents (
                id text primary key,
                title text not null,
                publisher text not null,
                url text not null unique,
                category text not null,
                language text not null,
                fetched_at text not null,
                published_at text null,
                review_status text not null,
                reviewed_by text null,
                reviewed_at text null,
                license_note text not null,
                headings text not null,
                text text not null,
                checksum text not null
            )
            SQL);

        $pdo->exec(<<<'SQL'
            create table chunks (
                id integer primary key autoincrement,
                document_id text not null references documents(id) on delete cascade,
                ordinal integer not null,
                text text not null,
                char_count integer not null
            )
            SQL);

        // The title is a separate FTS column so the retriever can boost it:
        // a title-only hit must rank at least as high as a body hit.
        $pdo->exec("create virtual table chunks_fts using fts5(title, text, tokenize='trigram')");

        $pdo->exec(<<<'SQL'
            create table keywords (
                term text not null,
                document_id text not null references documents(id) on delete cascade,
                weight integer not null default 1,
                primary key (term, document_id)
            )
            SQL);

        $pdo->exec('create table meta (key text primary key, value text not null)');

        $documentCount = 0;
        $chunkCount = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            $insertDocument = $pdo->prepare(<<<'SQL'
                insert into documents
                    (id, title, publisher, url, category, language, fetched_at, published_at,
                     review_status, reviewed_by, reviewed_at, license_note, headings, text, checksum)
                values
                    (:id, :title, :publisher, :url, :category, :language, :fetched_at, :published_at,
                     :review_status, :reviewed_by, :reviewed_at, :license_note, :headings, :text, :checksum)
                SQL);

            $insertChunk = $pdo->prepare(
                'insert into chunks (document_id, ordinal, text, char_count) values (?, ?, ?, ?)',
            );
            $insertFts = $pdo->prepare('insert into chunks_fts (rowid, title, text) values (?, ?, ?)');
            $insertKeyword = $pdo->prepare(
                'insert or ignore into keywords (term, document_id, weight) values (?, ?, ?)',
            );

            foreach ($sources as $source) {
                $chunks = $this->chunk((string) $source['text']);

                if ($chunks === []) {
                    $skipped++;

                    continue;
                }

                $checksum = hash('sha256', (string) $source['text']);

                $insertDocument->execute([
                    'id' => $source['id'],
                    'title' => $source['title'],
                    'publisher' => $source['publisher'],
                    'url' => $source['url'],
                    'category' => $source['category'],
                    'language' => $source['language'],
                    'fetched_at' => $source['fetched_at'],
                    'published_at' => $source['published_at'] ?? null,
                    'review_status' => $source['review_status'],
                    'reviewed_by' => $source['reviewed_by'] ?? null,
                    'reviewed_at' => $source['reviewed_at'] ?? null,
                    'license_note' => $source['license_note'] ?? '',
                    'headings' => (string) json_encode(
                        array_values($source['headings'] ?? []),
                        JSON_UNESCAPED_UNICODE,
                    ),
                    'text' => $source['text'],
                    'checksum' => $checksum,
                ]);

                foreach ($chunks as $ordinal => $chunk) {
                    $insertChunk->execute([$source['id'], $ordinal, $chunk, mb_strlen($chunk)]);
                    $chunkId = (int) $pdo->lastInsertId();
                    $insertFts->execute([
                        $chunkId,
                        ThaiText::normalise((string) $source['title']),
                        ThaiText::normalise($chunk),
                    ]);
                    $chunkCount++;
                }

                foreach ($this->keywords($source) as $term => $weight) {
                    $insertKeyword->execute([$term, $source['id'], $weight]);
                }

                $documentCount++;
            }

            $builtAt = now()->toIso8601String();
            $insertMeta = $pdo->prepare('insert into meta (key, value) values (?, ?)');
            $insertMeta->execute(['schema_version', (string) self::SCHEMA_VERSION]);
            $insertMeta->execute(['built_at', $builtAt]);
            $insertMeta->execute(['documents', (string) $documentCount]);
            $insertMeta->execute(['chunks', (string) $chunkCount]);

            $pdo->commit();
        } catch (PDOException $exception) {
            $pdo->rollBack();
            @unlink($temporary);

            throw KnowledgeCorpusReadonlyException::from($exception);
        }

        $pdo = null;

        // Windows keeps the SQLite handle locked until every statement and the
        // PDO object are released. Close them before the atomic rename.
        unset($insertDocument, $insertChunk, $insertFts, $insertKeyword, $insertMeta, $pdo);
        gc_collect_cycles();

        $renamed = false;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@rename($temporary, $targetPath)) {
                $renamed = true;
                break;
            }

            usleep(100_000);
        }

        if (! $renamed) {
            @unlink($temporary);

            throw new KnowledgeCorpusReadonlyException('Cannot move the built knowledge base into place.');
        }

        return [
            'documents' => $documentCount,
            'chunks' => $chunkCount,
            'skipped' => $skipped,
            'built_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sources(string $sourceDir): array
    {
        $files = glob(rtrim($sourceDir, '/\\').'/*.json') ?: [];
        sort($files);

        $sources = [];

        foreach ($files as $file) {
            $json = file_get_contents($file);

            if (! is_string($json) || trim($json) === '') {
                continue;
            }

            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                continue;
            }

            $decoded['_file'] = basename($file);
            $sources[] = $decoded;
        }

        return $sources;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<string>
     */
    public function validateSources(array $sources): array
    {
        $errors = [];
        $ids = [];
        $urls = [];
        $hosts = (array) config('knowledge.fetch.allowed_hosts', []);

        foreach ($sources as $source) {
            $id = is_string($source['id'] ?? null) ? $source['id'] : '';
            $label = $id !== '' ? $id : (string) ($source['_file'] ?? 'unknown');

            if (preg_match('/^[a-z0-9][a-z0-9-]{2,80}$/', $id) !== 1) {
                $errors[] = $label.': id must be a lowercase slug';
            } elseif (isset($ids[$id])) {
                $errors[] = $label.': duplicate id';
            }

            $ids[$id] = true;

            $title = trim((string) ($source['title'] ?? ''));
            $publisher = trim((string) ($source['publisher'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));
            $text = trim((string) ($source['text'] ?? ''));
            $category = (string) ($source['category'] ?? '');
            $review = (string) ($source['review_status'] ?? '');
            $language = (string) ($source['language'] ?? 'th');
            $fetchedAt = (string) ($source['fetched_at'] ?? '');

            if (mb_strlen($title) < 3 || mb_strlen($title) > 200) {
                $errors[] = $label.': title must be 3–200 characters';
            }

            if ($publisher === '') {
                $errors[] = $label.': publisher is required';
            }

            if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
                $errors[] = $label.': url must be an https URL';
            } else {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));

                if ($hosts !== [] && ! in_array($host, $hosts, true)) {
                    $errors[] = $label.': url host is not allow-listed';
                }

                $path = (string) parse_url($url, PHP_URL_PATH);

                foreach ((array) config('knowledge.fetch.disallowed_path_prefixes', []) as $prefix) {
                    if (is_string($prefix) && $prefix !== '' && str_starts_with($path, $prefix)) {
                        $errors[] = $label.': url path is disallowed by the source robots.txt';
                    }
                }

                if (isset($urls[$url])) {
                    $errors[] = $label.': duplicate url';
                }

                $urls[$url] = true;
            }

            if (mb_strlen($text) < 200) {
                $errors[] = $label.': text is too short (min 200 characters)';
            }

            if (! in_array($category, ['disease', 'prevention', 'news', 'guideline'], true)) {
                $errors[] = $label.': category must be disease|prevention|news|guideline';
            }

            if (! in_array($review, ['unreviewed', 'reviewed'], true)) {
                $errors[] = $label.': review_status must be unreviewed|reviewed';
            }

            if ($review === 'reviewed') {
                if (trim((string) ($source['reviewed_by'] ?? '')) === '') {
                    $errors[] = $label.': reviewed_by is required when review_status is reviewed';
                }

                if (strtotime((string) ($source['reviewed_at'] ?? '')) === false) {
                    $errors[] = $label.': reviewed_at must be a date when review_status is reviewed';
                }
            }

            if (! in_array($language, ['th', 'en'], true)) {
                $errors[] = $label.': language must be th|en';
            }

            if (strtotime($fetchedAt) === false) {
                $errors[] = $label.': fetched_at must be a date';
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $length = mb_strlen($text);
        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $end = min($start + $this->chunkChars, $length);
            $slice = mb_substr($text, $start, $end - $start);

            if ($end < $length) {
                $newline = mb_strrpos($slice, "\n");

                if ($newline !== false && $newline > (int) ($this->chunkChars * 0.6)) {
                    $slice = mb_substr($slice, 0, $newline);
                    $end = $start + $newline;
                }
            }

            $slice = trim($slice);

            if (mb_strlen($slice) >= 40) {
                $chunks[] = $slice;
            }

            if ($end >= $length) {
                break;
            }

            $start = max($start + 1, $end - $this->chunkOverlap);
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, int>
     */
    private function keywords(array $source): array
    {
        $terms = [];
        $title = (string) ($source['title'] ?? '');

        foreach (preg_split('/[()\/,;:|]+/u', $title) ?: [] as $part) {
            $part = $this->normaliseTerm($part);

            if ($part !== '') {
                $terms[$part] = ($terms[$part] ?? 0) + 3;
            }
        }

        foreach ((array) ($source['headings'] ?? []) as $heading) {
            $heading = $this->normaliseTerm((string) $heading);

            if ($heading !== '') {
                $terms[$heading] = ($terms[$heading] ?? 0) + 2;
            }
        }

        $category = $this->normaliseTerm((string) ($source['category'] ?? ''));

        if ($category !== '') {
            $terms[$category] = ($terms[$category] ?? 0) + 1;
        }

        return $terms;
    }

    private function normaliseTerm(string $term): string
    {
        $term = ThaiText::normalise(preg_replace('/\s+/u', ' ', $term) ?? $term);
        $term = trim($term, " \t\n\r\0\x0B.,;:!?()[]{}<>\"'");

        if (mb_strlen($term) < 2 || mb_strlen($term) > 80) {
            return '';
        }

        return $term;
    }
}
