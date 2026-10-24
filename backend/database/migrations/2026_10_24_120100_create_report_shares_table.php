<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * report_shares — capability links to a frozen `health_reports` row (DB-003).
 *
 * The raw token is returned exactly once and never stored; only its SHA-256
 * digest is persisted. Every row is tenant/workspace scoped and forced under
 * RLS (ADR-007), so authenticated callers can only revoke their own workspace's
 * shares.
 *
 * The public brief endpoint has no authenticated workspace context. Rather than
 * weakening tenant isolation or bypassing RLS with a SECURITY DEFINER function,
 * a second SELECT-only policy permits exactly the row whose `token_hash` equals
 * the transaction-local `app.report_share_token_hash` value set by the public
 * resolver. Knowledge of the 256-bit token is therefore the whole capability,
 * and no other row becomes visible. The policy is SELECT-only: INSERT, UPDATE
 * and DELETE still require a real tenant/workspace context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('report_id');
            $table->string('token_hash', 64);
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->integer('access_count')->default(0);
            $table->timestampTz('last_accessed_at')->nullable();
            // A digest, never the raw client address (N-02 / PDPA minimisation).
            $table->string('last_accessed_ip', 64)->nullable();
            $table->string('recipient_label')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_report_shares_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['report_id', 'tenant_id', 'workspace_id'], 'fk_report_shares_report')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_reports')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'fk_report_shares_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->unique('token_hash', 'uq_report_shares_token_hash');
            $table->index(['tenant_id', 'workspace_id'], 'ix_report_shares_tenant_workspace');
            $table->index('report_id', 'ix_report_shares_report');
        });

        DB::statement(
            'alter table report_shares add constraint chk_report_shares_access_count '
            .'check (access_count >= 0)'
        );

        DB::statement('alter table report_shares enable row level security');
        DB::statement('alter table report_shares force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on report_shares
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
                with check (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
        DB::statement(<<<'SQL'
            create policy public_token_lookup on report_shares
                for select
                using (
                    token_hash = nullif(current_setting('app.report_share_token_hash', true), '')
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists public_token_lookup on report_shares');
        DB::statement('drop policy if exists tenant_isolation on report_shares');
        DB::statement('alter table report_shares no force row level security');
        DB::statement('alter table report_shares disable row level security');

        Schema::dropIfExists('report_shares');
    }
};
