<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * security_audit_logs — security ledger with a hash chain (DM-A01..DM-A05).
 *
 * `seq` is the ordering key; `id` stays the primary key. Rows are append-only
 * and checked by a BEFORE UPDATE/DELETE trigger, which also applies to the
 * table owner because triggers ignore ownership.
 *
 * RLS follows the same two layers as the other tenant-scoped tables. The
 * workspace layer additionally permits `workspace_id IS NULL` because that
 * column is nullable by design (platform/system events). No context therefore
 * still yields zero rows, and workspaces cannot read each other's entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_audit_logs', function (Blueprint $table) {
            // Blueprint includes a `primary` command, so modifyIncrement does
            // not turn `seq` into a second primary key; the column is still
            // compiled as bigserial and keeps its own sequence.
            $table->uuid('id');
            $table->bigInteger('seq')->autoIncrement();
            $table->uuid('tenant_id');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type');
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->uuid('target_id')->nullable();
            $table->string('result');
            $table->string('reason_code')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->binary('prev_hash')->nullable();
            $table->binary('hash');
            $table->timestampsTz();

            $table->primary('id');
            $table->unique('seq', 'uq_security_audit_logs_seq');

            $table->foreign('tenant_id', 'fk_security_audit_logs_tenant')
                ->references('id')->on('tenants')
                ->restrictOnDelete();
            $table->foreign(['workspace_id', 'tenant_id'], 'fk_security_audit_logs_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_security_audit_logs_tenant_workspace');
            $table->index(['target_type', 'target_id'], 'ix_security_audit_logs_target');
            $table->index('occurred_at', 'ix_security_audit_logs_occurred_at');
        });

        DB::statement(
            'alter table security_audit_logs add constraint chk_security_audit_logs_actor_type '
            ."check (actor_type in ('user', 'system', 'ai', 'platform_staff'))"
        );
        DB::statement(
            'alter table security_audit_logs add constraint chk_security_audit_logs_result '
            ."check (result in ('allow', 'deny', 'error'))"
        );

        DB::statement(<<<'SQL'
            create or replace function forbid_mutation() returns trigger as $$
            begin
                raise exception 'append-only table: % rows cannot be modified or deleted', TG_TABLE_NAME
                    using errcode = '55000';
            end;
            $$ language plpgsql
        SQL);
        DB::statement(
            'create trigger security_audit_logs_no_update_delete before update or delete on security_audit_logs '
            .'for each row execute function forbid_mutation()'
        );

        DB::statement('alter table security_audit_logs enable row level security');
        DB::statement('alter table security_audit_logs force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on security_audit_logs
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and (
                        workspace_id is null
                        or workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                    )
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on security_audit_logs');
        DB::statement('alter table security_audit_logs no force row level security');
        DB::statement('alter table security_audit_logs disable row level security');

        DB::statement('drop trigger if exists security_audit_logs_no_update_delete on security_audit_logs');

        Schema::dropIfExists('security_audit_logs');
    }
};
