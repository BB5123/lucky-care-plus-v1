<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task_events — append-only history of every care-task transition (CT-004,
 * CRM-019). The row is written once and never updated: a trigger raises
 * SQLSTATE 55000 for every role, including the table owner.
 *
 * DELETE is deliberately still allowed for the retention job: `.ai/06 §8.1`
 * keeps `task_events` for 36 months by `created_at`, and DM-R05 forbids
 * deleting the *audit chain* (`audit_events`, `security_audit_logs`), not the
 * operational task history. `occurred_at` remains the domain event clock;
 * `created_at` is the storage clock the retention policy reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('task_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type')->default('user');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('event');
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_task_events_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['task_id', 'tenant_id', 'workspace_id'], 'fk_task_events_task')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('care_tasks')
                ->cascadeOnDelete();
            $table->foreign('actor_id', 'fk_task_events_actor')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_task_events_tenant_workspace');
            $table->index(['task_id', 'occurred_at'], 'ix_task_events_task_occurred');
            $table->index('occurred_at', 'ix_task_events_occurred_at');
            $table->index('created_at', 'ix_task_events_created_at');
        });

        DB::statement(
            'alter table task_events add constraint chk_task_events_actor_type '
            ."check (actor_type in ('user', 'system'))"
        );

        // Append-only guard. UPDATE is always rejected. DELETE is rejected for
        // every application path and is only allowed for the retention purge,
        // which opts in with the transaction-local `app.retention_purge` flag
        // (`.ai/06 §8.1` keeps task_events for 36 months; DM-R05 protects the
        // audit chain, not this operational history).
        DB::statement(<<<'SQL'
            create or replace function forbid_task_event_mutation() returns trigger as $$
            begin
                if tg_op = 'DELETE'
                   and coalesce(current_setting('app.retention_purge', true), '') = 'on' then
                    return old;
                end if;

                raise exception 'append-only table: % rows cannot be modified or deleted', TG_TABLE_NAME
                    using errcode = '55000';
            end;
            $$ language plpgsql
        SQL);
        DB::statement(
            'create trigger task_events_no_mutation before update or delete on task_events '
            .'for each row execute function forbid_task_event_mutation()'
        );

        DB::statement('alter table task_events enable row level security');
        DB::statement('alter table task_events force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on task_events
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists task_events_no_mutation on task_events');
        DB::statement('drop trigger if exists task_events_no_update on task_events');
        DB::statement('drop trigger if exists task_events_no_update_delete on task_events');
        DB::statement('drop function if exists forbid_task_event_mutation()');
        DB::statement('drop policy if exists tenant_isolation on task_events');
        DB::statement('alter table task_events no force row level security');
        DB::statement('alter table task_events disable row level security');

        Schema::dropIfExists('task_events');
    }
};
