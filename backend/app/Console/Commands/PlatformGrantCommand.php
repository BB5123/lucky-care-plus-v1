<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Workspace\Models\Tenant;
use App\Support\Database\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Grant or revoke the explicit platform-administrator flag (CR-005).
 *
 * This is a bootstrap operation for an installation: the first platform admin
 * cannot be created through the product, because the product requires a
 * platform admin to review sources. The CLI actor is `system`.
 *
 * Every invocation that reaches a user writes exactly one audit row. Revoking
 * the last remaining admin is refused so an installation cannot lock itself
 * out; the refusal is audited as a denied attempt.
 */
final class PlatformGrantCommand extends Command
{
    protected $signature = 'platform:grant
        {email : Email address of the existing user}
        {--revoke : Remove the platform-administrator flag instead of granting it}';

    protected $description = 'Grant or revoke platform-administrator access for a user';

    public function handle(SecurityAuditLogger $audit): int
    {
        $email = trim((string) $this->argument('email'));

        $user = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->first();

        if (! $user instanceof User) {
            $this->error('User not found: '.$email);

            return self::FAILURE;
        }

        $tenantId = Tenant::query()
            ->orderByRaw("case when slug = 'default' then 0 else 1 end")
            ->orderBy('id')
            ->value('id');

        if (! is_string($tenantId) || $tenantId === '') {
            $this->error('A tenant directory row is required to audit platform-admin changes.');

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');
        $refused = false;

        DB::transaction(function () use ($user, $revoke, &$refused): void {
            if ($revoke) {
                $adminIds = User::query()
                    ->where('is_platform_admin', true)
                    ->lockForUpdate()
                    ->pluck('id');

                if ($user->is_platform_admin && $adminIds->count() <= 1) {
                    $refused = true;

                    return;
                }

                $user->forceFill([
                    'is_platform_admin' => false,
                    'platform_admin_granted_at' => null,
                    'platform_admin_granted_by' => null,
                ])->save();

                return;
            }

            $user->forceFill([
                'is_platform_admin' => true,
                'platform_admin_granted_at' => now(),
                // The CLI actor is `system`, not a user row.
                'platform_admin_granted_by' => null,
            ])->save();
        });

        $newValue = $revoke ? false : true;

        TenantContext::run($tenantId, '', function () use ($audit, $user, $revoke, $refused, $newValue): void {
            $audit->record([
                'actor_id' => null,
                'actor_type' => 'system',
                'action' => $revoke ? 'platform.admin.revoke' : 'platform.admin.grant',
                'target_type' => 'user',
                // `target_id` is uuid in the ledger; user ids are bigint.
                'target_id' => null,
                'result' => $refused ? 'deny' : 'allow',
                'reason_code' => $refused ? 'last_platform_admin' : null,
                'metadata' => [
                    'target_user_id' => $user->id,
                    'is_platform_admin' => $refused ? true : $newValue,
                ],
            ]);
        });

        if ($refused) {
            $this->error('Refusing to revoke the last platform administrator.');

            return self::FAILURE;
        }

        $this->info(($revoke ? 'Revoked' : 'Granted').' platform administrator: '.$user->email);

        return self::SUCCESS;
    }
}
