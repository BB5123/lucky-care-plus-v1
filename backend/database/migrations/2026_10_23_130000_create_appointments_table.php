<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * appointments — the coordination surface around a doctor visit (`.ai/05 §8`,
 * `.ai/CRM_DOMAIN_ARCHITECTURE.md §4.3`).
 *
 * An appointment is *not* a medical record (APT-001): it records logistics,
 * not findings. `purpose` and `location` are free text and therefore
 * encrypted by the model (`.ai/06 §6`); nothing here stores the ciphertext
 * specially.
 *
 * Standard tenancy pattern: composite workspace/profile FKs (ADR-013),
 * forced RLS (ADR-007), UUIDv7 PK (ADR-008), `version` for optimistic locking
 * (DM-010) and soft deletes (CRM-020).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->unsignedBigInteger('created_by');
            $table->string('source')->default('manual');
            $table->string('title', 160);
            $table->string('provider_name')->nullable();
            $table->string('facility')->nullable();
            // Free-text visit purpose and place: encrypted at rest (.ai/06 §6).
            $table->text('purpose')->nullable();
            $table->text('location')->nullable();
            $table->timestampTz('scheduled_at');
            $table->smallInteger('duration_minutes')->nullable();
            $table->string('status')->default('planned');
            $table->text('cancelled_reason')->nullable();
            $table->uuid('rescheduled_from_id')->nullable();
            $table->uuid('transported_by_task_id')->nullable();
            $table->jsonb('prepared_documents')->default('[]');
            $table->uuid('follow_up_task_id')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_appointments_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_appointments_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'fk_appointments_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            // Composite target for the two care-task links. `restrictOnDelete`
            // keeps the coordination history intact: a task referenced by an
            // appointment cannot be hard-deleted out from under it.
            $table->foreign(
                ['transported_by_task_id', 'tenant_id', 'workspace_id'],
                'fk_appointments_transported_by_task',
            )
                ->references(['id', 'tenant_id', 'workspace_id'])->on('care_tasks')
                ->restrictOnDelete();
            $table->foreign(
                ['follow_up_task_id', 'tenant_id', 'workspace_id'],
                'fk_appointments_follow_up_task',
            )
                ->references(['id', 'tenant_id', 'workspace_id'])->on('care_tasks')
                ->restrictOnDelete();

            $table->unique(
                ['id', 'tenant_id', 'workspace_id'],
                'uq_appointments_id_tenant_workspace',
            );
            $table->index(['tenant_id', 'workspace_id'], 'ix_appointments_tenant_workspace');
            $table->index(['profile_id', 'scheduled_at'], 'ix_appointments_profile_scheduled');
            $table->index(
                ['workspace_id', 'status', 'scheduled_at'],
                'ix_appointments_workspace_status_scheduled',
            );
        });

        // The self-reference is added after the table exists so PostgreSQL can
        // resolve the primary key it points at. History is kept: the old row
        // is marked `rescheduled`, the new row carries this back-reference
        // (domain rule in AppointmentService).
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('rescheduled_from_id', 'fk_appointments_rescheduled_from')
                ->references('id')->on('appointments')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table appointments add constraint chk_appointments_status '
            .'check (status in ('
            ."'planned', 'confirmed', 'attended', 'rescheduled', "
            ."'cancelled', 'missed', 'needs_followup'))"
        );
        DB::statement(
            'alter table appointments add constraint chk_appointments_duration '
            .'check (duration_minutes is null or (duration_minutes between 5 and 480))'
        );
        DB::statement(
            'alter table appointments add constraint chk_appointments_documents_array '
            ."check (jsonb_typeof(prepared_documents) = 'array')"
        );

        DB::statement('alter table appointments enable row level security');
        DB::statement('alter table appointments force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on appointments
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on appointments');
        DB::statement('alter table appointments no force row level security');
        DB::statement('alter table appointments disable row level security');

        Schema::dropIfExists('appointments');
    }
};
