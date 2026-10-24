<?php

namespace App\Modules\Documents\Infrastructure\Scanning;

/**
 * Fail-closed stand-in used when no scanner is configured.
 *
 * With `documents.scan_required = true` this verdict turns an upload into the
 * existing `503 scan_unavailable` response without touching storage or the
 * database. It is the default binding, so today's behaviour is unchanged.
 */
final class UnavailableScanner implements MalwareScanner
{
    public function scan(string $absolutePath): ScanVerdict
    {
        return ScanVerdict::error('unavailable', 'not_configured');
    }
}
