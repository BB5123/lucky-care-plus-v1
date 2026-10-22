<?php

namespace App\Modules\CareCrm\Domain;

use Illuminate\Http\Request;

/**
 * Reads the optimistic-locking token required by care-task transitions
 * (AP-007). A missing, malformed or wildcard token is treated exactly like a
 * mismatch: the client did not prove which version it edited, so the write is
 * refused with 412.
 */
final class VersionHeader
{
    public static function require(Request $request): int
    {
        $raw = $request->header('If-Match');

        if (! is_string($raw) || ! preg_match('/^(?:W\/)?"?(\d+)"?$/', trim($raw), $matches)) {
            throw new VersionConflictException;
        }

        return (int) $matches[1];
    }
}
