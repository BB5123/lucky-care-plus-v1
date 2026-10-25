<?php

namespace App\Modules\Retention\Services;

use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Workspace\Models\Workspace;

/**
 * Writes the `retention.archive_read` audit row when an operator command reads
 * an archived account (RETENTION-MODEL.md §6: every access is audited).
 *
 * The HTTP gate keeps archived data out of the app, so the remaining reads are
 * the console manifest/drill commands - and those decrypt or expose the
 * inventory, which is exactly what an access log should record. The weekly
 * `retention:status --verify-archives` probe is deliberately **not** audited:
 * it is a system health check that runs for every archived workspace and would
 * bury the human reads it is meant to surface.
 *
 * The caller must already be inside the workspace's tenant context (the audit
 * table is RLS-forced). No PHI is written: command name, target and a boolean.
 */
final class ArchiveAccessAuditor
{
    public function __construct(private readonly SecurityAuditLogger $audit) {}

    public function read(Workspace $workspace, string $command, ?bool $verified = null): void
    {
        $this->audit->record([
            'actor_type' => 'system',
            'action' => 'retention.archive_read',
            'target_type' => 'workspace',
            'target_id' => (string) $workspace->id,
            'result' => 'allow',
            'reason_code' => null,
            'metadata' => [
                'command' => $command,
                'verified' => $verified,
            ],
        ]);
    }
}
