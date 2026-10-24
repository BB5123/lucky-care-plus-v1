<?php

namespace Tests\Feature\Support;

use App\Support\Database\TenantContext;
use App\Support\Id\Uuid7;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: `TenantContext::run()` must work without an outer transaction.
 *
 * `set_config(..., true)` is SET LOCAL: outside a transaction it is discarded
 * before the callback's first query, RLS denies every row, and the workspace
 * resolver returns null — which made every workspace-scoped route answer 404
 * on a real server. RefreshDatabase wraps each test in a transaction, so the
 * feature suite never saw it; this class deliberately does not use it.
 */
class TenantContextTest extends TestCase
{
    public function test_run_opens_a_transaction_when_the_caller_has_none(): void
    {
        // Proves the precondition this regression is about.
        $this->assertSame(0, DB::transactionLevel());

        $tenant = Uuid7::generate();
        $workspace = Uuid7::generate();

        $seen = TenantContext::run(
            $tenant,
            $workspace,
            static fn (): array => TenantContext::pair(),
        );

        $this->assertSame($tenant, $seen['tenant_id']);
        $this->assertSame($workspace, $seen['workspace_id']);
    }

    public function test_run_restores_the_previous_context_inside_a_transaction(): void
    {
        DB::transaction(function (): void {
            $outerTenant = Uuid7::generate();
            $outerWorkspace = Uuid7::generate();
            $innerTenant = Uuid7::generate();
            $innerWorkspace = Uuid7::generate();

            TenantContext::set($outerTenant, $outerWorkspace);

            $seen = TenantContext::run(
                $innerTenant,
                $innerWorkspace,
                static fn (): array => TenantContext::pair(),
            );

            $this->assertSame($innerTenant, $seen['tenant_id']);
            $this->assertSame($innerWorkspace, $seen['workspace_id']);

            $restored = TenantContext::pair();

            $this->assertSame($outerTenant, $restored['tenant_id']);
            $this->assertSame($outerWorkspace, $restored['workspace_id']);
        });
    }
}
