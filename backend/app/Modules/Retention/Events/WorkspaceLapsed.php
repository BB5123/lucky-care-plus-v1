<?php

namespace App\Modules\Retention\Events;

/**
 * A workspace entered `read_only` because its subscription lapsed.
 *
 * Dispatched by `WorkspaceLifecycle::lapse()` only when the transition really
 * happened (a second lapse is a no-op and fires nothing). The listener offers
 * the export, which is the "export offered on day 1" promise of
 * RETENTION-MODEL.md §4 case 1.
 */
final class WorkspaceLapsed
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $workspaceId,
        public readonly string $reason,
    ) {}
}
