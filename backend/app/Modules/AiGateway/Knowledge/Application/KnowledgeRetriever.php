<?php

namespace App\Modules\AiGateway\Knowledge\Application;

use App\Modules\AiGateway\Knowledge\Domain\KnowledgeUnavailableException;
use PDO;
use Throwable;

/**
 * Read-only retrieval over the SQLite knowledge base (TASK G4-KB).
 *
 * Retrieval is lexical: SQLite FTS5 with the trigram tokenizer, plus a small
 * keyword vocabulary (titles and section headings) to turn a Thai question
 * without spaces into searchable terms. No embeddings, no network, no writes.
 *
 * The class is intentionally the only place that knows about SQLite. A
 * Qdrant/pgvector retriever can implement the same `search()` contract later
 * without touching the gateway or the prompt builder.
 */
final class KnowledgeRetriever
{
    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? (string) config('knowledge.sqlite_path');
    }

    public function isAvailable(): bool
    {
        $path = $this->path();

        return $path !== '' && is_file($path) && filesize($path) > 0;
    }

    /**
     * @return list<array{
     *     id: int,
     *     document_id: string,
     *     title: string,
     *     publisher: string,
     *     url: string,
     *     category: string,
     *     published_at: ?string,
     *     fetched_at: string,
     *     review_status: string,
     *     text: string,
     *     score: float
     * }>
     */
    public function search(string $question, ?int $limit = null): array
    {
        $question = trim($question);

        if ($question === '') {
            return [];
        }

        $limit = max(1, min(20, $limit ?? (int) config('knowledge.top_k', 5)));
        $pdo = $this->connection();
        $terms = $this->termsFor($pdo, $question);

        if ($terms === []) {
            return [];
        }

        $long = array_values(array_filter($terms, static fn (string $term): bool => mb_strlen($term) >= 3));

        if ($long !== []) {
            $query = implode(' OR ', array_map(
                static fn (string $term): string => '"'.str_replace('"', '""', $term).'"',
                array_slice($long, 0, 5),
            ));

            $statement = $pdo->prepare(<<<'SQL'
                select c.id, c.document_id, c.text, c.ordinal,
                       d.title, d.publisher, d.url, d.category, d.published_at,
                       d.fetched_at, d.review_status,
                       bm25(chunks_fts) as score
                from chunks_fts
                join chunks c on c.id = chunks_fts.rowid
                join documents d on d.id = c.document_id
                where chunks_fts match :query
                order by score asc, c.id asc
                limit :limit
                SQL);

            $statement->bindValue(':query', $query);
            $statement->bindValue(':limit', $limit * 3, PDO::PARAM_INT);
            $statement->execute();
        } else {
            // Every term is shorter than the trigram minimum: fall back to a
            // substring scan. The corpus is small and this path is rare.
            $needle = '%'.$terms[0].'%';
            $statement = $pdo->prepare(<<<'SQL'
                select c.id, c.document_id, c.text, c.ordinal,
                       d.title, d.publisher, d.url, d.category, d.published_at,
                       d.fetched_at, d.review_status,
                       0.0 as score
                from chunks c
                join documents d on d.id = c.document_id
                where c.text like :needle
                order by c.id asc
                limit :limit
                SQL);

            $statement->bindValue(':needle', $needle);
            $statement->bindValue(':limit', $limit * 3, PDO::PARAM_INT);
            $statement->execute();
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $this->deduplicate($rows, $limit);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $rows, int $limit): array
    {
        $perDocument = [];
        $result = [];

        foreach ($rows as $row) {
            $documentId = (string) $row['document_id'];
            $perDocument[$documentId] = $perDocument[$documentId] ?? 0;

            if ($perDocument[$documentId] >= 2) {
                continue;
            }

            $perDocument[$documentId]++;

            $result[] = [
                'id' => (int) $row['id'],
                'document_id' => $documentId,
                'title' => (string) $row['title'],
                'publisher' => (string) $row['publisher'],
                'url' => (string) $row['url'],
                'category' => (string) $row['category'],
                'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
                'fetched_at' => (string) $row['fetched_at'],
                'review_status' => (string) $row['review_status'],
                'text' => (string) $row['text'],
                'score' => (float) $row['score'],
            ];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Terms to search for: the longest keywords from the corpus that the
     * question actually contains. Falls back to whitespace tokens for English
     * or already-spaced Thai.
     *
     * @return list<string>
     */
    private function termsFor(PDO $pdo, string $question): array
    {
        $keywords = $pdo->query('select distinct term from keywords')->fetchAll(PDO::FETCH_COLUMN);
        $matched = [];

        foreach ($keywords as $term) {
            if (! is_string($term) || mb_strlen($term) < 2) {
                continue;
            }

            if (mb_stripos($question, $term) !== false) {
                $matched[$term] = mb_strlen($term);
            }
        }

        arsort($matched);

        // Thai questions often omit a prefix ("หวัดใหญ่" for "ไข้หวัดใหญ่") or
        // a compound prefix ("ป้องกัน" for "การป้องกัน"). Try the longest
        // suffix/prefix of a keyword; arbitrary internal substrings produce
        // false matches across word boundaries ("ข้องกั" inside "เกี่ยวข้องกับ").
        if ($matched === []) {
            foreach ($keywords as $term) {
                if (! is_string($term)) {
                    continue;
                }

                $length = mb_strlen($term);

                if ($length < 5) {
                    continue;
                }

                $best = '';

                for ($size = min(12, $length); $size >= 5 && $best === ''; $size--) {
                    foreach ([mb_substr($term, $length - $size, $size), mb_substr($term, 0, $size)] as $piece) {
                        if (mb_stripos($question, $piece) !== false) {
                            $best = $piece;
                            break;
                        }
                    }
                }

                if ($best !== '') {
                    $matched[$best] = mb_strlen($best);
                }
            }

            arsort($matched);
        }
        $selected = [];

        foreach (array_keys($matched) as $term) {
            $contained = false;

            foreach ($selected as $already) {
                if (mb_stripos($already, $term) !== false) {
                    $contained = true;
                    break;
                }
            }

            if (! $contained) {
                $selected[] = $term;
            }

            if (count($selected) >= 5) {
                break;
            }
        }

        if ($selected !== []) {
            return $selected;
        }

        $tokens = preg_split('/[\s,.;:!?()\[\]{}"\']+/u', $question) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen(trim($token)) >= 3,
        ));

        if ($tokens !== []) {
            return array_slice($tokens, 0, 5);
        }

        return [];
    }

    private function connection(): PDO
    {
        $path = $this->path();

        if (! $this->isAvailable()) {
            throw new KnowledgeUnavailableException('Knowledge base file is missing.');
        }

        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA query_only = ON');
        } catch (Throwable $exception) {
            throw new KnowledgeUnavailableException('Cannot open the knowledge base.', 0, $exception);
        }

        return $pdo;
    }
}
