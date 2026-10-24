<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Context after step 3. No real name is retained here; `pseudonymCount` is the
 * only trace of how many distinct names were replaced.
 */
final class ScrubbedContext
{
    /**
     * @param  array<string, mixed>  $sections
     */
    public function __construct(
        public readonly array $sections,
        public readonly int $pseudonymCount,
    ) {}
}
