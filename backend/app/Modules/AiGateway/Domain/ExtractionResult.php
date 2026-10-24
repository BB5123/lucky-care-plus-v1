<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Outcome of one vision-extraction attempt (TASK P-3, AIG-007).
 *
 * A success is always a *draft*: the caller must confirm before the normal
 * measurements/medication endpoints create anything. This object never
 * carries an image and never causes a health-row write.
 */
final class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $warnings
     */
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $kind,
        public readonly array $fields,
        public readonly array $warnings,
        public readonly float $confidence,
        public readonly ?string $documentId,
        public readonly ?string $model,
        public readonly ?string $errorCode,
        public readonly int $httpStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $warnings
     */
    public static function draft(
        string $kind,
        array $fields,
        array $warnings,
        float $confidence,
        string $documentId,
        string $model,
    ): self {
        return new self(
            true,
            $kind,
            $fields,
            $warnings,
            max(0.0, min(1.0, $confidence)),
            $documentId,
            $model,
            null,
            200,
        );
    }

    public static function failed(string $errorCode, int $httpStatus): self
    {
        return new self(false, null, [], [], 0.0, null, null, $errorCode, $httpStatus);
    }
}
