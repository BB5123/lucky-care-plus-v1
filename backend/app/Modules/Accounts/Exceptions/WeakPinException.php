<?php

namespace App\Modules\Accounts\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a PIN is structurally valid but too weak to store.
 */
final class WeakPinException extends InvalidArgumentException
{
    public static function make(): self
    {
        return new self('PIN is too weak.');
    }
}
