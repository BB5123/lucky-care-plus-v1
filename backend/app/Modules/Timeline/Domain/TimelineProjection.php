<?php

namespace App\Modules\Timeline\Domain;

/**
 * One source event, described in the shape the projector understands.
 *
 * The same descriptor is produced by the live observers and by the rebuild
 * command, so "what a source event projects to" has exactly one definition
 * (CRM-011). `metadata` may only carry ids, enums and timestamps (DM-007).
 */
final readonly class TimelineProjection
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $type,
        public string $refType,
        public string $refId,
        public string $occurredAt,
        public string $summaryKey,
        public array $metadata = [],
    ) {}
}
