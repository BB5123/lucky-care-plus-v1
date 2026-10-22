<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit_events — business audit trail ("who did what, with what result").
 *
 * Append-only (DM-009). `metadata` must be scrubbed before insertion; it must
 * never contain PHI (N-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type');
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->uuid('target_id')->nullable();
            $table->string('result');
            $table->string('reason_code')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampsTz();

            // Audit rows are part of the retention record: deleting a workspace
            // must not silently cascade them away (ADR-016).
            $table->foreign(['workspace_id', 'tenant_id'], 'fk_audit_events_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_audit_events_tenant_workspace');
            $table->index(['target_type', 'target_id'], 'ix_audit_events_target');
            $table->index('occurred_at', 'ix_audit_events_occurred_at');
        });

        DB::statement(
            'alter table audit_events add constraint chk_audit_events_actor_type '
            ."check (actor_type in ('user', 'system', 'ai', 'platform_staff'))"
        );
        DB::statement(
            'alter table audit_events add constraint chk_audit_events_result '
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
            'create trigger audit_events_no_update_delete before update or delete on audit_events '
            .'for each row execute function forbid_mutation()'
        );

        DB::statement('alter table audit_events enable row level security');
        DB::statement('alter table audit_events force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on audit_events
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on audit_events');
        DB::statement('alter table audit_events no force row level security');
        DB::statement('alter table audit_events disable row level security');

        DB::statement('drop trigger if exists audit_events_no_update_delete on audit_events');

        Schema::dropIfExists('audit_events');
    }
};
