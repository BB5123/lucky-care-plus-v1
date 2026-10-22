<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * care_tasks — the aggregate root of care coordination (`.ai/05`, ADR-001).
 *
 * Standard tenancy pattern: composite workspace FK (ADR-013), composite profile
 * FK, forced RLS (ADR-007), UUIDv7 PK (ADR-008) and `version` for optimistic
 * locking (DM-010). `description` is free text and therefore encrypted by the
 * model (`.ai/06 §6`); nothing here stores the ciphertext specially.
 *
 * `template_id` is nullable with no FK yet: recurring templates are explicitly
 * out of scope for P1.3, but the column lets a future occurrence-generation
 * job write it without another migration on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->unsignedBigInteger('created_by');
            $table->uuid('template_id')->nullable();
            $table->string('tier');
            $table->string('type');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('priority')->default('normal');
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('timezone')->default('Asia/Bangkok');
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->string('verification')->default('not_required');
            $table->boolean('verification_required')->default(false);
            $table->string('evidence_policy')->default('none');
            $table->smallInteger('escalation_level')->default(0);
            $table->text('blocked_reason')->nullable();
            // CT-005: a blocked task must record when it will be reviewed.
            $table->timestampTz('review_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->integer('version')->default(1);
            $table->string('source')->default('manual');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_care_tasks_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_care_tasks_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'fk_care_tasks_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            // Composite target for task_assignments / task_events / task_evidence.
            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_care_tasks_id_tenant_workspace');
            $table->index(['tenant_id', 'workspace_id'], 'ix_care_tasks_tenant_workspace');
            $table->index(['workspace_id', 'status', 'due_at'], 'ix_care_tasks_workspace_status_due');
            $table->index(['profile_id', 'status'], 'ix_care_tasks_profile_status');
            $table->unique(
                ['workspace_id', 'idempotency_key'],
                'uq_care_tasks_workspace_idempotency_key',
            );
        });

        DB::statement(
            'alter table care_tasks add constraint chk_care_tasks_tier '
            ."check (tier in ('routine', 'delegated', 'clinical'))"
        );
        DB::statement(
            'alter table care_tasks add constraint chk_care_tasks_type '
            .'check (type in ('
            ."'MEDICATION_SUPPORT', 'VITAL_MEASUREMENT', 'APPOINTMENT', "
            ."'DOCUMENT_FOLLOW_UP', 'DAILY_CARE', 'FOLLOW_UP', 'CUSTOM'))"
        );
        DB::statement(
            'alter table care_tasks add constraint chk_care_tasks_priority '
            ."check (priority in ('low', 'normal', 'high'))"
        );
        DB::statement(
            'alter table care_tasks add constraint chk_care_tasks_status '
            .'check (status in ('
            ."'scheduled', 'open', 'assigned', 'accepted', 'in_progress', "
            ."'blocked', 'completed', 'cancelled'))"
        );
        DB::statement(
            'alter table care_tasks add constraint chk_care_tasks_outcome '
            ."check (outcome is null or outcome in ('done', 'skipped', 'missed', 'failed'))"
        );
        DB::statement(
            'alter table care_tasks add constraint chk_care_tasks_verification '
            ."check (verification in ('not_required', 'pending', 'verified', 'rejected'))"
        );
        DB::statement(
            'alter table care_tasks add constraint chk_care_tasks_evidence_policy '
            ."check (evidence_policy in ('none', 'value', 'photo', 'both'))"
        );

        DB::statement('alter table care_tasks enable row level security');
        DB::statement('alter table care_tasks force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on care_tasks
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on care_tasks');
        DB::statement('alter table care_tasks no force row level security');
        DB::statement('alter table care_tasks disable row level security');

        Schema::dropIfExists('care_tasks');
    }
};
