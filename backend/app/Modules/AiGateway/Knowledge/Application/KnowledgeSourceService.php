<?php

namespace App\Modules\AiGateway\Knowledge\Application;

use App\Modules\AiGateway\Knowledge\Domain\KnowledgeCorpusReadonlyException;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeSourceInvalidException;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeSourceNotFoundException;
use ErrorException;
use PDOException;
use RuntimeException;

/**
 * Read and review the public knowledge corpus (CR-005).
 *
 * This is the single code path shared by `knowledge:review` and the
 * platform-admin HTTP API: one place validates a source, writes its review
 * metadata and optionally rebuilds the SQLite corpus. The controller and the
 * console command only translate arguments and exceptions.
 *
 * The corpus is platform data: no tenant, no workspace, no health row.
 */
final class KnowledgeSourceService
{
    private const STALE_DAYS = 180;

    public function __construct(private readonly KnowledgeBaseBuilder $builder) {}

    /**
     * Every source, optionally filtered by review status and by a substring of
     * title or publisher.
     *
     * @return list<array{
     *     id: string,
     *     title: string,
     *     publisher: string,
     *     url: string,
     *     category: string,
     *     language: string,
     *     fetched_at: string,
     *     review_status: string,
     *     reviewed_by: ?string,
     *     reviewed_at: ?string,
     *     stale: bool
     * }>
     */
    public function list(?string $status = null, ?string $query = null): array
    {
        $rows = array_map(
            fn (array $source): array => $this->present($source),
            $this->builder->sources($this->directory()),
        );

        if ($status !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['review_status'] === $status,
            ));
        }

        $query = $query === null ? '' : trim($query);

        if ($query !== '') {
            $needle = mb_strtolower($query);

            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => mb_strpos(mb_strtolower($row['title']), $needle) !== false
                    || mb_strpos(mb_strtolower($row['publisher']), $needle) !== false,
            ));
        }

        return $rows;
    }

    /**
     * Rewrite a source's review fields and optionally rebuild the corpus.
     *
     * @return array{source: array<string, mixed>, stats: ?array{documents: int, chunks: int, skipped: int, built_at: string}}
     *
     * @throws KnowledgeSourceNotFoundException
     * @throws KnowledgeSourceInvalidException
     * @throws KnowledgeCorpusReadonlyException
     */
    public function review(string $id, string $reviewer, bool $build = true, string $status = 'reviewed'): array
    {
        $id = trim($id);

        if (preg_match('/^[a-z0-9][a-z0-9-]{2,80}$/', $id) !== 1) {
            throw new KnowledgeSourceInvalidException(['id' => ['The source id must be a lowercase slug.']]);
        }

        if (! in_array($status, ['reviewed', 'unreviewed'], true)) {
            throw new KnowledgeSourceInvalidException(['status' => ['status must be reviewed|unreviewed.']]);
        }

        $reviewer = trim($reviewer);

        if ($status === 'reviewed' && $reviewer === '') {
            throw new KnowledgeSourceInvalidException([
                'reviewer' => ['The reviewer is required when marking a source reviewed.'],
            ]);
        }

        $path = $this->pathFor($id);

        if (! is_file($path)) {
            throw new KnowledgeSourceNotFoundException;
        }

        $json = file_get_contents($path);

        if (! is_string($json) || trim($json) === '') {
            throw new KnowledgeSourceInvalidException(['source' => ['The source file is empty.']]);
        }

        try {
            $source = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new KnowledgeSourceInvalidException(['source' => ['The source file is not valid JSON.']]);
        }

        if (! is_array($source) || ($source['id'] ?? null) !== $id) {
            throw new KnowledgeSourceInvalidException([
                'source' => ['The source file does not carry the expected id.'],
            ]);
        }

        if ($status === 'reviewed') {
            $source['review_status'] = 'reviewed';
            $source['reviewed_by'] = $reviewer;
            $source['reviewed_at'] = now()->toDateString();
        } else {
            $source['review_status'] = 'unreviewed';
            $source['reviewed_by'] = null;
            $source['reviewed_at'] = null;
        }

        $errors = $this->builder->validateSources([$source]);

        if ($errors !== []) {
            throw new KnowledgeSourceInvalidException($errors);
        }

        $encoded = json_encode(
            $source,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Could not encode the source.');
        }

        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            $written = file_put_contents($temporary, $encoded."\n");
        } catch (ErrorException|RuntimeException $exception) {
            // Laravel's error handler turns the "permission denied" warning
            // into an ErrorException. A read-only volume, a full disk or a
            // directory this process does not own all end up here.
            @unlink($temporary);

            throw KnowledgeCorpusReadonlyException::from($exception);
        }

        if ($written === false) {
            @unlink($temporary);

            throw new KnowledgeCorpusReadonlyException('The knowledge corpus is not writable.');
        }

        // Write into the same directory and rename atomically. Writing the
        // source in place would succeed even when the directory is read-only
        // (the existing file itself is still writable) and leave a half-
        // updated source behind when the SQLite rebuild fails.
        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new KnowledgeCorpusReadonlyException('The knowledge corpus is not writable.');
        }

        $stats = null;

        if ($build) {
            try {
                $stats = $this->builder->build($this->directory(), (string) config('knowledge.sqlite_path'));
            } catch (ErrorException|PDOException $exception) {
                // A PDOException opened before the builder's own transaction
                // guard, or a filesystem warning raised while writing the
                // SQLite file, is the same deployment condition.
                throw KnowledgeCorpusReadonlyException::from($exception);
            }
        }

        return ['source' => $this->present($source), 'stats' => $stats];
    }

    public function directory(): string
    {
        return (string) config('knowledge.sources_path');
    }

    public function pathFor(string $id): string
    {
        return rtrim($this->directory(), '/\\').'/'.$id.'.json';
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{
     *     id: string,
     *     title: string,
     *     publisher: string,
     *     url: string,
     *     category: string,
     *     language: string,
     *     fetched_at: string,
     *     review_status: string,
     *     reviewed_by: ?string,
     *     reviewed_at: ?string,
     *     stale: bool
     * }
     */
    private function present(array $source): array
    {
        $fetchedAt = (string) ($source['fetched_at'] ?? '');
        $fetchedTimestamp = strtotime($fetchedAt);

        return [
            'id' => (string) ($source['id'] ?? ''),
            'title' => (string) ($source['title'] ?? ''),
            'publisher' => (string) ($source['publisher'] ?? ''),
            'url' => (string) ($source['url'] ?? ''),
            'category' => (string) ($source['category'] ?? ''),
            'language' => (string) ($source['language'] ?? ''),
            'fetched_at' => $fetchedAt,
            'review_status' => (string) ($source['review_status'] ?? ''),
            'reviewed_by' => isset($source['reviewed_by']) ? (string) $source['reviewed_by'] : null,
            'reviewed_at' => isset($source['reviewed_at']) ? (string) $source['reviewed_at'] : null,
            'stale' => $fetchedTimestamp !== false
                && $fetchedTimestamp < now()->subDays(self::STALE_DAYS)->getTimestamp(),
        ];
    }
}
