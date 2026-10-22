<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditEvent;
use App\Support\Database\TenantContext;
use InvalidArgumentException;

/**
 * Writes the business audit trail (`audit_events`) for domain mutations.
 *
 * Distinct from {@see SecurityAuditLogger}: this answers "who changed what,
 * when, with what result" for product history, not "was access allowed".
 * Tenant/workspace identity always comes from the active server-side context,
 * never from the caller (N-01), and free-text health values are rejected so a
 * record can never become a PHI store (N-02).
 */
final class BusinessAuditLogger
{
    /** @var list<string> */
    private const FORBIDDEN_PHI_KEYS = ['name', 'phone', 'email', 'value', 'note', 'diagnosis', 'notes', 'reaction'];

    /**
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void
    {
        $this->assertNoPhi($event);

        $tenantId = TenantContext::tenantId();
        $workspaceId = TenantContext::workspaceId();

        if ($tenantId === null || $workspaceId === null) {
            throw new InvalidArgumentException(
                'Business audit events require a server-side tenant and workspace context (N-01).'
            );
        }

        AuditEvent::query()->create([
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'actor_id' => $event['actor_id'] ?? null,
            'actor_type' => $event['actor_type'] ?? 'user',
            'action' => $event['action'],
            'target_type' => $event['target_type'] ?? null,
            'target_id' => $event['target_id'] ?? null,
            'result' => $event['result'] ?? 'allow',
            'reason_code' => $event['reason_code'] ?? null,
            'metadata' => $event['metadata'] ?? [],
            'occurred_at' => $event['occurred_at'] ?? now(),
        ]);
    }

    private function assertNoPhi(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_PHI_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Business audit payload rejected: forbidden PHI key '{$key}' (N-02)."
                );
            }

            $this->assertNoPhi($value);
        }
    }
}
