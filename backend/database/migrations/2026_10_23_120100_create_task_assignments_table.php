<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task_assignments — who is responsible for a care task (CT-001/CT-002).
 *
 * At most one active primary assignee per task is guaranteed by a partial
 * unique index, not by application code alone (`.ai/05` §3 CT-001). Assistants
 * and verifiers may coexist, and a released/declined row stays in the table so
 * the history of responsibility survives a reassignment (CT-007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('task_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('primary');
            $table->boolean('can_complete')->default(false);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_task_assignments_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['task_id', 'tenant_id', 'workspace_id'], 'fk_task_assignments_task')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('care_tasks')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'fk_task_assignments_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('assigned_by', 'fk_task_assignments_assigned_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_task_assignments_tenant_workspace');
            $table->index(['task_id', 'role'], 'ix_task_assignments_task_role');
            $table->index(['workspace_id', 'user_id'], 'ix_task_assignments_workspace_user');
        });

        DB::statement(
            'alter table task_assignments add constraint chk_task_assignments_role '
            ."check (role in ('primary', 'assistant', 'verifier'))"
        );

        // CT-001: one active primary per task. Released rows are history, so a
        // new primary becomes possible after `released_at` is set (CT-T09).
        DB::statement(
            'create unique index uq_task_assignments_active_primary '
            ."on task_assignments (task_id) where role = 'primary' and released_at is null"
        );

        DB::statement('alter table task_assignments enable row level security');
        DB::statement('alter table task_assignments force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on task_assignments
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop index if exists uq_task_assignments_active_primary');
        DB::statement('drop policy if exists tenant_isolation on task_assignments');
        DB::statement('alter table task_assignments no force row level security');
        DB::statement('alter table task_assignments disable row level security');

        Schema::dropIfExists('task_assignments');
    }
};
