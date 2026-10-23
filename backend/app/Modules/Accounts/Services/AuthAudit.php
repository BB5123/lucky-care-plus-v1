<?php

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Workspace\Models\Tenant;
use App\Support\Database\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Security audit for global identity events (login success/failure).
 *
 * `security_audit_logs` itself is tenant-scoped, so a server-side tenant has
 * to be chosen before writing. Authentication is not tenant-scoped, so the
 * default tenant (or the first tenant when no default exists) is used and the
 * row is written with `workspace_id = null`. Failure to audit must not turn a
 * denied login into a server error; it is reported and swallowed.
 */
final class AuthAudit
{
    public function __construct(private readonly SecurityAuditLogger $audit) {}

    /**
     * Records that the development demo mode echoed an OTP in an API response.
     *
     * The row carries the actor and the fact, never the code (N-02). It exists so
     * that demo-mode usage is visible in the audit trail and cannot be mistaken
     * for a normal issuance.
     */
    public function otpDemoIssued(User $user): void
    {
        $this->recordForUser($user, 'auth.otp.demo_issued', 'otp_demo_mode');
    }

    public function loginDenied(?User $user): void
    {
        try {
            $tenantId = $this->defaultTenantId();

            if ($tenantId === null) {
                return;
            }

            DB::transaction(function () use ($tenantId, $user): void {
                TenantContext::set($tenantId, '');

                try {
                    $this->audit->record([
                        'actor_id' => $user?->id,
                        'actor_type' => $user === null ? 'anonymous' : 'user',
                        'action' => 'auth.login',
                        'target_type' => 'user',
                        'target_id' => null,
                        'result' => 'deny',
                        'reason_code' => 'invalid_credentials',
                        'metadata' => $user === null ? [] : ['user_id' => $user->id],
                    ]);
                } finally {
                    TenantContext::clear();
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Writes one allow row for a global identity event for the given user.
     *
     * Failure to audit must not break the caller (the demo code has already been
     * handed out and the flow must continue), so the error is reported and
     * swallowed — the same trade-off as `loginDenied`.
     */
    private function recordForUser(User $user, string $action, string $reasonCode): void
    {
        try {
            $tenantId = $this->defaultTenantId();

            if ($tenantId === null) {
                return;
            }

            DB::transaction(function () use ($tenantId, $user, $action, $reasonCode): void {
                TenantContext::set($tenantId, '');

                try {
                    $this->audit->record([
                        'actor_id' => $user->id,
                        'actor_type' => 'user',
                        'action' => $action,
                        'target_type' => 'user',
                        'target_id' => null,
                        'result' => 'allow',
                        'reason_code' => $reasonCode,
                        'metadata' => ['user_id' => $user->id],
                    ]);
                } finally {
                    TenantContext::clear();
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function defaultTenantId(): ?string
    {
        return Tenant::query()
            ->orderByRaw("case when slug = 'default' then 0 else 1 end")
            ->orderBy('id')
            ->value('id');
    }
}
