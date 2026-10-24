<?php

namespace App\Modules\HealthRecord\Domain;

/**
 * A sanitised reason why the queued export could not be built.
 *
 * The message is intentionally fixed and contains no profile data, no health
 * value, no document name and no storage path. The queued job maps this to the
 * `data_exports.error_code` column and to a PHI-free audit row; the actual
 * exception chain is never logged.
 */
final class DataExportBuildFailure extends \RuntimeException
{
    public function __construct(private readonly string $errorCode)
    {
        parent::__construct('The data export could not be built.');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
