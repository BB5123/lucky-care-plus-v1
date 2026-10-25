<?php

namespace App\Modules\AiGateway\Knowledge\Domain;

use RuntimeException;
use Throwable;

/**
 * The corpus (source JSON or SQLite file) could not be written.
 *
 * This is a deployment/filesystem condition, not a validation error: the
 * process is running against a read-only mount, a full disk, or a directory it
 * does not own. The API maps it to 503 `knowledge_corpus_readonly` and writes
 * a failed audit row instead of leaking an unhandled `ErrorException` as a 500
 * (CR-005-FIX).
 *
 * The message is deliberately generic: the previous exception is kept for the
 * server log, but a filesystem path or source text never reaches the response.
 */
final class KnowledgeCorpusReadonlyException extends RuntimeException
{
    public static function from(Throwable $previous): self
    {
        return new self('The knowledge corpus is not writable.', 0, $previous);
    }
}
