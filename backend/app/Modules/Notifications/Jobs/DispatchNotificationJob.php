<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one notification off the request path.
 *
 * The job has no request context, so it opens its own tenant/workspace window
 * before calling the dispatcher. That window is transaction-local
 * (`set_config(..., true)`), which is why the whole dispatch runs inside a
 * database transaction: a queue worker is not inside one, and RLS would
 * otherwise deny every row.
 *
 * A failure is logged with machine data only (template key, subject type,
 * exception class — never params or recipient identity) and rethrown so the
 * queue retries it (`tries = 3`, backoff 10s then 60s).
 */
final class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<int>  $recipientUserIds
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $tenantId,
        public string $workspaceId,
        public string $templateKey,
        public array $recipientUserIds,
        public array $params,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
    ) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        try {
            DB::transaction(function () use ($dispatcher): void {
                TenantContext::run($this->tenantId, $this->workspaceId, function () use ($dispatcher): void {
                    $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

                    // Re-check at delivery time, not only at dispatch time: the
                    // job may have been queued before the account lapsed, and a
                    // read_only/archived account must not receive it (P2b). The
                    // job is dropped, not retried - retrying could never succeed.
                    $stateValue = $workspace instanceof Workspace
                        ? $workspace->retentionState()->value
                        : 'unknown';

                    if (! $workspace instanceof Workspace
                        || ! $workspace->retentionState()->acceptsBackgroundWork()) {
                        Log::info('notification.dispatch.skipped_workspace_state', [
                            'template_key' => $this->templateKey,
                            'subject_type' => $this->subjectType,
                            'retention_state' => $stateValue,
                        ]);

                        return;
                    }

                    $dispatcher->dispatch(
                        $this->templateKey,
                        $this->recipientUserIds,
                        $this->params,
                        [
                            'tenant_id' => $this->tenantId,
                            'workspace_id' => $this->workspaceId,
                            'subject_type' => $this->subjectType,
                            'subject_id' => $this->subjectId,
                        ],
                    );
                });
            });
        } catch (Throwable $exception) {
            // No params, no recipient ids: a failed dispatch must not become a
            // PHI log line (N-02). The queue record keeps the retry payload.
            Log::warning('notification.dispatch.failed', [
                'template_key' => $this->templateKey,
                'subject_type' => $this->subjectType,
                'error' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
