<?php

namespace App\Modules\Retention\Exceptions;

use DomainException;

/**
 * A tenant retention override is outside the platform bounds (DM-R06).
 */
final class RetentionOverrideException extends DomainException
{
    public static function outOfBounds(string $type, int $min, int $max): self
    {
        return new self(
            "Retention override for [{$type}] must be between {$min} and {$max} months."
        );
    }

    public static function notOverridable(string $type): self
    {
        return new self("Retention type [{$type}] is fixed and cannot be overridden.");
    }

    public static function invalidValue(string $type): self
    {
        return new self("Retention override for [{$type}] must be an integer number of months.");
    }
}
