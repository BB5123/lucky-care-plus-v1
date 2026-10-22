<?php

namespace App\Support\Id;

use Illuminate\Support\Str;

/**
 * Generates RFC 4122 UUIDv7 identifiers on the application side.
 *
 * UUIDv7 is time-ordered, which keeps PostgreSQL B-tree indexes compact while
 * still being non-enumerable enough to avoid trivial ID guessing (ADR-008).
 * UUIDs are never an authorization mechanism — every access still goes through
 * the authorization layer.
 */
final class Uuid7
{
    public static function generate(): string
    {
        return (string) Str::uuid7();
    }
}
