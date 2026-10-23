<?php

namespace App\Modules\Timeline\Domain;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Trusted tenant/workspace/profile identity for one projection write.
 *
 * It is captured at observer time (inside the source write) so the deferred
 * `afterCommit` callback never has to guess identity from the row or from
 * client input (N-01). Values must come from persisted model data.
 */
final readonly class TimelineSubject
{
    public function __construct(
        public string $tenantId,
        public string $workspaceId,
        public string $profileId,
    ) {
        foreach ([$tenantId, $workspaceId, $profileId] as $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException('Timeline projection requires UUID subject identifiers.');
            }
        }
    }
}
