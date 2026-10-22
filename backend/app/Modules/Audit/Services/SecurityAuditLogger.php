<?php

namespace App\Modules\Audit\Services;

use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Appends rows to `security_audit_logs` and verifies the hash chain.
 *
 * Chain: hash = sha256(prev_hash || canonical_json(row)) (DM-A02).
 * `prev_hash` is taken from the highest `seq` row visible to the current
 * tenant context, while an advisory transaction lock serialises appenders so
 * two concurrent writes cannot fork the chain.
 *
 * The hash covers the immutable event fields only: id, tenant_id, occurred_at,
 * actor/target/result/reason, workspace_id and metadata. `seq`, `prev_hash`,
 * `hash`, `created_at` and `updated_at` are excluded because the sequence is
 * assigned by the database after the hash is computed.
 *
 * PHI guard: any event (including nested metadata) whose key is `name`,
 * `phone`, `email`, `value`, `note` or `diagnosis` is rejected before insert.
 * Logs are not a data store; N-02 forbids PHI in logs, and a hash chain makes
 * a leak permanent and hard to redact.
 */
final class SecurityAuditLogger
{
    private const CHAIN_LOCK = 917000001;

    /** @var list<string> */
    private const FORBIDDEN_PHI_KEYS = ['name', 'phone', 'email', 'value', 'note', 'diagnosis'];

    /**
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void
    {
        $this->assertNoPhi($event);

        $tenantId = TenantContext::tenantId() ?? $event['tenant_id'] ?? null;
        if (! is_string($tenantId) || $tenantId === '') {
            throw new InvalidArgumentException(
                'Security audit events require a server-side tenant context (N-01).'
            );
        }

        // The active context wins over any value supplied by the caller, so a
        // payload cannot redirect a row into another tenant/workspace (N-01).
        $workspaceId = TenantContext::workspaceId() ?? $event['workspace_id'] ?? null;

        $row = [
            'id' => is_string($event['id'] ?? null) ? $event['id'] : Uuid7::generate(),
            'tenant_id' => $tenantId,
            'occurred_at' => $this->normaliseTimestamp($event['occurred_at'] ?? null),
            'actor_id' => $event['actor_id'] ?? null,
            'actor_type' => $this->requiredString($event, 'actor_type'),
            'action' => $this->requiredString($event, 'action'),
            'target_type' => $event['target_type'] ?? null,
            'target_id' => $event['target_id'] ?? null,
            'result' => $this->requiredString($event, 'result'),
            'reason_code' => $event['reason_code'] ?? null,
            'workspace_id' => is_string($workspaceId) && $workspaceId !== '' ? $workspaceId : null,
            'metadata' => $this->normaliseMetadata($event['metadata'] ?? []),
        ];

        DB::transaction(function () use ($row): void {
            DB::select('select pg_advisory_xact_lock(?)', [self::CHAIN_LOCK]);

            $previous = DB::selectOne(
                "select encode(hash, 'hex') as hash_hex "
                .'from security_audit_logs order by seq desc limit 1 for update'
            );

            $prevHex = $previous?->hash_hex;
            $prevRaw = $prevHex === null ? '' : (hex2bin($prevHex) ?: '');
            $hashHex = hash('sha256', $prevRaw.$this->canonicalJson($row));

            DB::insert(
                'insert into security_audit_logs '
                .'(id, tenant_id, occurred_at, actor_id, actor_type, action, target_type, target_id, '
                .'result, reason_code, workspace_id, metadata, prev_hash, hash, created_at, updated_at) '
                .'values (?, ?, ?::timestamptz, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, '
                ."decode(?, 'hex'), decode(?, 'hex'), now(), now())",
                [
                    $row['id'],
                    $row['tenant_id'],
                    $row['occurred_at'],
                    $row['actor_id'],
                    $row['actor_type'],
                    $row['action'],
                    $row['target_type'],
                    $row['target_id'],
                    $row['result'],
                    $row['reason_code'],
                    $row['workspace_id'],
                    $this->canonicalJson($row['metadata']),
                    $prevHex,
                    $hashHex,
                ],
            );
        });
    }

    /**
     * Recompute the chain over the rows visible to the current tenant context.
     */
    public function verifyChain(): bool
    {
        $rows = DB::select(
            'select id, tenant_id, occurred_at, actor_id, actor_type, action, target_type, target_id, '
            .'result, reason_code, workspace_id, metadata::text as metadata, '
            ."encode(prev_hash, 'hex') as prev_hash_hex, encode(hash, 'hex') as hash_hex "
            .'from security_audit_logs order by seq asc'
        );

        $expectedPrev = null;

        foreach ($rows as $row) {
            $storedPrev = $row->prev_hash_hex;
            if (($storedPrev ?? null) !== $expectedPrev) {
                return false;
            }

            $payload = [
                'id' => $row->id,
                'tenant_id' => $row->tenant_id,
                'occurred_at' => $this->normaliseTimestamp($row->occurred_at),
                'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                'actor_type' => $row->actor_type,
                'action' => $row->action,
                'target_type' => $row->target_type,
                'target_id' => $row->target_id,
                'result' => $row->result,
                'reason_code' => $row->reason_code,
                'workspace_id' => $row->workspace_id,
                'metadata' => $this->normaliseMetadata(
                    $row->metadata === null ? [] : (json_decode($row->metadata, true) ?? [])
                ),
            ];

            $prevRaw = $expectedPrev === null ? '' : (hex2bin($expectedPrev) ?: '');
            $recomputed = hash('sha256', $prevRaw.$this->canonicalJson($payload));

            if (! hash_equals($recomputed, (string) $row->hash_hex)) {
                return false;
            }

            $expectedPrev = $recomputed;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function requiredString(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Security audit event is missing '{$key}'.");
        }

        return $value;
    }

    /**
     * Recursively reject payload keys that would put PHI into an append-only
     * log (N-02 / DM-A03). Values are never echoed in the exception message.
     */
    private function assertNoPhi(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_PHI_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Security audit payload rejected: forbidden PHI key '{$key}' (N-02)."
                );
            }

            $this->assertNoPhi($value);
        }
    }

    private function normaliseTimestamp(mixed $value): string
    {
        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable(is_string($value) && $value !== '' ? $value : 'now');

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    /**
     * Recursively sort keys so the JSON encoding is stable across inserts and
     * verification reads.
     */
    private function normaliseMetadata(mixed $metadata): mixed
    {
        if (! is_array($metadata)) {
            return $metadata;
        }

        if (array_is_list($metadata)) {
            return array_map(fn (mixed $value) => $this->normaliseMetadata($value), $metadata);
        }

        ksort($metadata);

        foreach ($metadata as $key => $value) {
            $metadata[$key] = $this->normaliseMetadata($value);
        }

        return $metadata;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->normaliseMetadata($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
