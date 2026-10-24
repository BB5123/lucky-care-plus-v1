<?php

namespace App\Modules\AiGateway\Domain;

/**
 * Scoped, not-yet-scrubbed context. Section keys are the frozen vocabulary
 * (`profile.basic`, `vitals`, `medications`, `appointments`, `tasks`).
 */
final class FetchedContext
{
    /**
     * @param  array<string, mixed>  $sections
     * @param  list<array{type: string, id: string}>  $recordRefs
     * @param  list<string>  $personNames  values to pseudonymize in step 3
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $recordRefs,
        public readonly array $personNames,
    ) {}
}
