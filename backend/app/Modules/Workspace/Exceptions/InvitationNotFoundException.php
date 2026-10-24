<?php

namespace App\Modules\Workspace\Exceptions;

use RuntimeException;

/**
 * Raised when an invitation id is unknown or belongs to another workspace
 * (HTTP 404, never 403 — the id must not be an enumeration oracle).
 */
final class InvitationNotFoundException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Invitation not found.');
    }
}
