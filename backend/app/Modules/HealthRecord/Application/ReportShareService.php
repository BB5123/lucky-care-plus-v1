<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\HealthRecord\Domain\HealthReportNotFoundException;
use App\Modules\HealthRecord\Domain\ReportShareNotFoundException;
use App\Modules\HealthRecord\Models\HealthReport;
use App\Modules\HealthRecord\Models\ReportShare;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Capability-link lifecycle for Doctor Briefs (DB-003..DB-005).
 *
 * The raw token is returned once and only its SHA-256 digest is stored. Public
 * resolution is intentionally capability-based: anyone holding the 256-bit
 * token may read the frozen payload, which is exactly the clinic use case. The
 * endpoint never reveals a token, and the audit row never contains one.
 */
final class ReportShareService
{
    /** Transaction-local GUC read by the SELECT-only public policy. */
    private const LOOKUP_SETTING = 'app.report_share_token_hash';

    private const DEFAULT_TTL_MINUTES = 30;

    private const MIN_TTL_MINUTES = 5;

    /**
     * Blueprint DB-003 (`.ai/05` §9): a share token lives at most 30 minutes.
     * A ticket may ask for a longer window; the blueprint wins.
     */
    private const MAX_TTL_MINUTES = 30;

    public function __construct(
        private readonly BusinessAuditLogger $businessAudit,
        private readonly SecurityAuditLogger $securityAudit,
    ) {}

    /**
     * @return array{share: ReportShare, token: string, url: string}
     */
    public function share(
        Workspace $workspace,
        HealthReport $report,
        User $actor,
        ?int $expiresInMinutes = null,
        ?string $recipientLabel = null,
    ): array {
        if ($report->workspace_id !== $workspace->id || ! $report->isActive()) {
            throw new HealthReportNotFoundException;
        }

        $minutes = $expiresInMinutes ?? self::DEFAULT_TTL_MINUTES;

        if ($minutes < self::MIN_TTL_MINUTES || $minutes > self::MAX_TTL_MINUTES) {
            throw ValidationException::withMessages([
                'expires_in_minutes' => ['The link lifetime must be between 5 and 30 minutes.'],
            ]);
        }

        $token = $this->newToken();
        $hash = hash('sha256', $token);
        $expiresAt = now()->addMinutes($minutes);

        $share = DB::transaction(function () use (

            $report,
            $actor,
            $hash,
            $expiresAt,
            $recipientLabel,
        ): ReportShare {
            $share = ReportShare::query()->create([
                'tenant_id' => $report->tenant_id,
                'workspace_id' => $report->workspace_id,
                'report_id' => $report->id,
                'token_hash' => $hash,
                'created_by' => $actor->id,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'access_count' => 0,
                'last_accessed_at' => null,
                'last_accessed_ip' => null,
                'recipient_label' => $recipientLabel,
            ]);

            $this->businessAudit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'report.share.create',
                'target_type' => 'health_report',
                'target_id' => $report->id,
                'result' => 'allow',
                'metadata' => [
                    'share_id' => $share->id,
                    'expires_at' => $share->expires_at->toIso8601String(),
                ],
            ]);

            return $share;
        });

        return [
            'share' => $share,
            'token' => $token,
            'url' => '/api/v1/briefs/'.$token,
        ];
    }

    /**
     * Idempotent revoke. A second call is a successful no-op.
     */
    public function revoke(
        Workspace $workspace,
        HealthReport $report,
        string $shareId,
        User $actor,
    ): ReportShare {
        return DB::transaction(function () use ($workspace, $report, $shareId, $actor): ReportShare {
            $share = ReportShare::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->where('report_id', $report->id)
                ->whereKey($shareId)
                ->lockForUpdate()
                ->first();

            if (! $share instanceof ReportShare) {
                throw new ReportShareNotFoundException;
            }

            if ($share->revoked_at === null) {
                $share->forceFill(['revoked_at' => now()])->save();

                $this->businessAudit->record([
                    'actor_id' => $actor->id,
                    'actor_type' => 'user',
                    'action' => 'report.share.revoke',
                    'target_type' => 'health_report',
                    'target_id' => $report->id,
                    'result' => 'allow',
                    'metadata' => ['share_id' => $share->id],
                ]);
            }

            return $share->refresh();
        });
    }

    /**
     * Resolve a public capability and return the frozen payload.
     *
     * @return array<string, mixed>
     */
    public function access(string $token, ?string $ipAddress): array
    {
        // A malformed value cannot match any row. Reject before touching the
        // database so arbitrary path input never becomes a query parameter.
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            throw new ReportShareNotFoundException;
        }

        $hash = hash('sha256', $token);

        try {
            return DB::transaction(function () use ($hash, $ipAddress): array {
                DB::selectOne(
                    'select set_config(?, ?, true) as value',
                    [self::LOOKUP_SETTING, $hash],
                );

                // The SELECT-only policy exposes exactly this hash; no tenant
                // context is needed for the capability lookup itself.
                $share = ReportShare::query()->where('token_hash', $hash)->first();

                if (! $share instanceof ReportShare) {
                    throw new ReportShareNotFoundException;
                }

                return TenantContext::run(
                    $share->tenant_id,
                    $share->workspace_id,
                    function () use ($share, $ipAddress): array {
                        $report = HealthReport::query()
                            ->whereKey($share->report_id)
                            ->first();

                        if (! $report instanceof HealthReport
                            || ! $report->isActive()
                            || $share->revoked_at !== null
                            || ! $share->expires_at->isFuture()) {
                            throw new ReportShareNotFoundException;
                        }

                        $share->increment('access_count');
                        $share->forceFill([
                            'last_accessed_at' => now(),
                            'last_accessed_ip' => $ipAddress === null
                                ? null
                                : hash('sha256', $ipAddress),
                        ])->save();
                        $share->refresh();

                        // The ledger records the event, never the token or any
                        // payload field (N-02, DB-004).
                        $this->securityAudit->record([
                            'actor_id' => null,
                            'actor_type' => 'system',
                            'action' => 'report.share.accessed',
                            'target_type' => 'health_report',
                            'target_id' => $report->id,
                            'result' => 'allow',
                            'metadata' => [
                                'share_id' => $share->id,
                                'access_count' => $share->access_count,
                            ],
                        ]);

                        return $report->payload;
                    },
                );
            });
        } finally {
            $this->clearLookupHash();
        }
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function clearLookupHash(): void
    {
        try {
            DB::statement("select set_config('".self::LOOKUP_SETTING."', '', false)");
        } catch (Throwable) {
            // A closed/aborted connection already discarded the transaction-
            // local setting; there is nothing left to clean up.
        }
    }
}
