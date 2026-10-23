<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the delivery ledger used by the reminder commands.
 *
 * The ledger's RLS policy only exposes rows to their owner or while the
 * dispatcher's explicit `app.notification_system` window is open. A scheduled
 * command has no acting user, so it opens that same window — transaction-local
 * and always closed again in `finally` — purely to answer the idempotency
 * question. It never writes here.
 */
final class NotificationDeliveryLedger
{
    public function hasDelivery(
        string $templateKey,
        string $subjectId,
        string $tenantId,
        string $workspaceId,
    ): bool {
        return DB::transaction(function () use ($templateKey, $subjectId, $tenantId, $workspaceId): bool {
            DB::selectOne('select set_config(?, ?, true)', ['app.notification_system', 'on']);

            try {
                return NotificationDelivery::query()
                    ->where('tenant_id', $tenantId)
                    ->where('workspace_id', $workspaceId)
                    ->where('template_key', $templateKey)
                    ->where('subject_id', $subjectId)
                    ->exists();
            } finally {
                DB::selectOne('select set_config(?, ?, true)', ['app.notification_system', '']);
            }
        });
    }
}
