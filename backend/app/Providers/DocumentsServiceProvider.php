<?php

namespace App\Providers;

use App\Modules\Documents\Infrastructure\Scanning\ClamAvScanner;
use App\Modules\Documents\Infrastructure\Scanning\MalwareScanner;
use App\Modules\Documents\Infrastructure\Scanning\UnavailableScanner;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the malware-scanner boundary from `config/documents.php`.
 *
 * `null` (the default) keeps the fail-closed {@see UnavailableScanner}: an
 * upload with `scan_required = true` returns 503. `clamav` selects the unix
 * socket adapter. An unknown value deliberately falls back to the unavailable
 * scanner instead of guessing, so a typo cannot silently disable scanning.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, function (): MalwareScanner {
            return match ((string) config('documents.scanner', '')) {
                'clamav' => new ClamAvScanner(
                    (string) config('documents.clamav.socket', ClamAvScanner::DEFAULT_SOCKET),
                    (float) config('documents.clamav.timeout_seconds', 10.0),
                ),
                default => new UnavailableScanner,
            };
        });
    }
}
