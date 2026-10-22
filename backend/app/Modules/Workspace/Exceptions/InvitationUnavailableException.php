<?php

namespace App\Modules\Workspace\Exceptions;

use RuntimeException;

/**
 * Raised when an invitation token is consumed but no membership exists for
 * the accepting user (HTTP 404 — consumed tokens are not reusable).
 */
final class InvitationUnavailableException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Invitation is not available.');
    }
}
