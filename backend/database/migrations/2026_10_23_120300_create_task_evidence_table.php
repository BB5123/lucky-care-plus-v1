<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task_evidence — proof attached to a care task.
 *
 * Clinical completion requires evidence that matches the task's
 * `evidence_policy` (CT-005, CRM-008). `note` is free text and therefore
 * encrypted by the model. `document_id` is intentionally FK-less because the
 * `documents` table does not exist yet; the column reserves the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('task_id');
            $table->string('type');
            $table->string('metric')->nullable();
            $table->decimal('value', 14, 4)->nullable();
            $table->string('unit')->nullable();
            $table->uuid('document_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_task_evidence_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['task_id', 'tenant_id', 'workspace_id'], 'fk_task_evidence_task')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('care_tasks')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'fk_task_evidence_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_task_evidence_tenant_workspace');
            $table->index(['task_id', 'created_at'], 'ix_task_evidence_task_created');
        });

        DB::statement(
            'alter table task_evidence add constraint chk_task_evidence_type '
            ."check (type in ('value', 'photo', 'note'))"
        );

        DB::statement('alter table task_evidence enable row level security');
        DB::statement('alter table task_evidence force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on task_evidence
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on task_evidence');
        DB::statement('alter table task_evidence no force row level security');
        DB::statement('alter table task_evidence disable row level security');

        Schema::dropIfExists('task_evidence');
    }
};
