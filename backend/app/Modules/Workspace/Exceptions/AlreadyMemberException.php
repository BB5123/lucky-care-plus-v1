<?php

namespace App\Modules\Workspace\Exceptions;

use RuntimeException;

/**
 * Raised when an invitation would create a second membership for the same
 * user in the same workspace (HTTP 409).
 */
final class AlreadyMemberException extends RuntimeException
{
    public static function make(): self
    {
        return new self('User is already a member of this workspace.');
    }
}
