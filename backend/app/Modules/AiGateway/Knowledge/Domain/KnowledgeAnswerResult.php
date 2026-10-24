<?php

namespace App\Modules\AiGateway\Knowledge\Domain;

/**
 * Outcome of one knowledge-answer attempt (TASK G4-KB).
 *
 * A success always carries the sources that were retrieved and cited. A
 * failure carries a stable error code and an optional Thai message; the
 * controller maps both to the API envelope.
 */
final class KnowledgeAnswerResult
{
    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array{requests_today?: int, remaining?: int}  $usage
     */
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $answer,
        public readonly array $sources,
        public readonly ?string $message,
        public readonly ?string $errorCode,
        public readonly int $httpStatus,
        public readonly ?string $disclaimer,
        public readonly array $usage,
        public readonly ?string $model,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array{requests_today?: int, remaining?: int}  $usage
     */
    public static function success(
        string $answer,
        array $sources,
        string $disclaimer,
        array $usage,
        string $model,
    ): self {
        return new self(true, $answer, $sources, null, null, 200, $disclaimer, $usage, $model);
    }

    public static function failed(string $errorCode, int $httpStatus, ?string $message = null): self
    {
        return new self(false, null, [], $message, $errorCode, $httpStatus, null, [], null);
    }
}
