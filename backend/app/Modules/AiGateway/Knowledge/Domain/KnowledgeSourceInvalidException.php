<?php

namespace App\Modules\AiGateway\Knowledge\Domain;

use RuntimeException;

/**
 * A knowledge source failed validation before it could be written.
 *
 * The messages are about the source structure (id, title, url, …), never the
 * source text, so they are safe to show to an authorized platform admin and
 * to the console.
 */
final class KnowledgeSourceInvalidException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Knowledge source is invalid.');
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
