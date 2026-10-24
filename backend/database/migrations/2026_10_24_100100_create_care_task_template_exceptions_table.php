<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * care_task_template_exceptions — "not this occurrence" overrides.
 *
 * `skip` suppresses every slot of that local date. `reschedule` creates the
 * occurrence at `rescheduled_to` instead of the scheduled wall-clock slots.
 * The unique `(template_id, occurrence_date)` key makes applying the same
 * exception twice a conflict, so the API treats it as an upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_task_template_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('template_id');
            $table->date('occurrence_date');
            $table->string('action');
            $table->timestampTz('rescheduled_to')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_task_template_exceptions_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['template_id', 'tenant_id', 'workspace_id'], 'fk_task_template_exceptions_template')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('care_task_templates')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'fk_task_template_exceptions_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->unique(
                ['template_id', 'occurrence_date'],
                'uq_task_template_exceptions_template_date',
            );
            $table->index(['tenant_id', 'workspace_id'], 'ix_task_template_exceptions_tenant_workspace');
        });

        DB::statement(
            'alter table care_task_template_exceptions add constraint chk_task_template_exceptions_action '
            ."check (action in ('skip', 'reschedule'))"
        );
        DB::statement(
            'alter table care_task_template_exceptions add constraint chk_task_template_exceptions_reschedule '
            ."check (action <> 'reschedule' or rescheduled_to is not null)"
        );

        DB::statement('alter table care_task_template_exceptions enable row level security');
        DB::statement('alter table care_task_template_exceptions force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on care_task_template_exceptions
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on care_task_template_exceptions');
        DB::statement('alter table care_task_template_exceptions no force row level security');
        DB::statement('alter table care_task_template_exceptions disable row level security');

        Schema::dropIfExists('care_task_template_exceptions');
    }
};
