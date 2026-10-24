<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Endpoint-facing outcome. `status` mirrors `ai_requests.status` so the
 * controller can map every attempt to one response without inspecting
 * exceptions.
 */
final class GatewayResult
{
    /**
     * @param  list<array{type: string, id: string}>  $recordRefs
     * @param  array{requests_today: int, remaining: int}  $usage
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $summary,
        public readonly ?string $disclaimer,
        public readonly array $recordRefs,
        public readonly array $usage,
        public readonly ?string $model,
        public readonly ?string $errorCode,
        public readonly int $httpStatus,
    ) {}

    /**
     * @param  list<array{type: string, id: string}>  $recordRefs
     * @param  array{requests_today: int, remaining: int}  $usage
     */
    public static function success(
        string $summary,
        string $disclaimer,
        array $recordRefs,
        array $usage,
        string $model,
    ): self {
        return new self(
            'succeeded',
            $summary,
            $disclaimer,
            $recordRefs,
            $usage,
            $model,
            null,
            200,
        );
    }

    /**
     * @param  array{requests_today: int, remaining: int}  $usage
     */
    public static function refused(string $errorCode, int $httpStatus, array $usage = []): self
    {
        return new self(
            'refused',
            null,
            null,
            [],
            $usage,
            null,
            $errorCode,
            $httpStatus,
        );
    }

    /**
     * @param  array{requests_today: int, remaining: int}  $usage
     */
    public static function failed(string $errorCode, int $httpStatus, array $usage = []): self
    {
        return new self(
            'failed',
            null,
            null,
            [],
            $usage,
            null,
            $errorCode,
            $httpStatus,
        );
    }

    public static function disabled(): self
    {
        return new self('disabled', null, null, [], [], null, 'ai_disabled', 503);
    }
}
