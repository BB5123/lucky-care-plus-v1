<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * care_task_templates — the recurring schedule behind materialised care tasks
 * (`.ai/05 §4`, `.ai/CRM_DOMAIN_ARCHITECTURE.md §3/§4.2`).
 *
 * A template is an independent aggregate root: editing it must never rewrite
 * the occurrences (care_tasks) already materialised from an earlier version
 * (RC-003, CRM-021). Standard tenancy applies: composite workspace/profile
 * foreign keys (ADR-013), UUIDv7 PK (ADR-008), forced RLS (ADR-007) and a
 * `version` column for optimistic locking (DM-010).
 *
 * `description` is free text and therefore encrypted by the model. The
 * schedule itself is stored structurally (frequency/interval/days/times)
 * rather than as an RRULE string; the materialiser walks it in the template
 * timezone so a DST change keeps the local wall clock (RC-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_task_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->unsignedBigInteger('created_by');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('tier');
            $table->string('type');
            $table->string('priority')->default('normal');

            $table->string('frequency');
            $table->smallInteger('interval')->default(1);
            // ISO-8601 weekday numbers (1 = Monday ... 7 = Sunday); required
            // for `weekly`, ignored for the other frequencies.
            $table->jsonb('days_of_week')->nullable();
            // Local wall-clock slots, `HH:MM`, one to six per occurrence day.
            $table->jsonb('times_of_day');
            $table->string('timezone')->default('Asia/Bangkok');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->string('evidence_policy')->default('none');
            $table->boolean('verification_required')->default(false);

            $table->string('assignee_strategy')->default('unassigned');
            $table->unsignedBigInteger('assigned_user_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_care_task_templates_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_care_task_templates_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'fk_care_task_templates_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();
            $table->foreign('assigned_user_id', 'fk_care_task_templates_assigned_user')
                ->references('id')->on('users')
                ->nullOnDelete();

            // Composite target for care_task_template_exceptions.
            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_care_task_templates_id_tenant_workspace');
            $table->index(['tenant_id', 'workspace_id'], 'ix_care_task_templates_tenant_workspace');
            $table->index(['workspace_id', 'is_active'], 'ix_care_task_templates_workspace_active');
            $table->index(['profile_id', 'is_active'], 'ix_care_task_templates_profile_active');
        });

        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_tier '
            ."check (tier in ('routine', 'delegated', 'clinical'))"
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_type '
            .'check (type in ('
            ."'MEDICATION_SUPPORT', 'VITAL_MEASUREMENT', 'APPOINTMENT', "
            ."'DOCUMENT_FOLLOW_UP', 'DAILY_CARE', 'FOLLOW_UP', 'CUSTOM'))"
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_priority '
            ."check (priority in ('low', 'normal', 'high'))"
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_frequency '
            ."check (frequency in ('daily', 'weekly', 'monthly'))"
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_interval '
            .'check ("interval" between 1 and 30)'
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_times '
            ."check (jsonb_typeof(times_of_day) = 'array' "
            .'and jsonb_array_length(times_of_day) between 1 and 6)'
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_days '
            ."check (days_of_week is null or jsonb_typeof(days_of_week) = 'array')"
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_evidence_policy '
            ."check (evidence_policy in ('none', 'value', 'photo', 'both'))"
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_assignee_strategy '
            ."check (assignee_strategy in ('fixed', 'rotating', 'unassigned'))"
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_fixed_assignee '
            ."check (assignee_strategy <> 'fixed' or assigned_user_id is not null)"
        );
        DB::statement(
            'alter table care_task_templates add constraint chk_care_task_templates_date_range '
            .'check (ends_on is null or ends_on >= starts_on)'
        );

        DB::statement('alter table care_task_templates enable row level security');
        DB::statement('alter table care_task_templates force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on care_task_templates
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on care_task_templates');
        DB::statement('alter table care_task_templates no force row level security');
        DB::statement('alter table care_task_templates disable row level security');

        Schema::dropIfExists('care_task_templates');
    }
};
