<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * medication_plans — the prescribed/reported plan a dose record belongs to.
 *
 * Same tenancy pattern as every other health table: composite workspace FK
 * (ADR-013), composite profile FK, forced RLS (ADR-007), UUIDv7 PK (ADR-008),
 * provenance columns (`.ai/06 §3`) and `version` for optimistic locking
 * (DM-010). `instructions` is free text, so it is field-encrypted by the model
 * (`.ai/06 §6`).
 *
 * `schedule_times` is a JSONB array of `HH:MM` slots. The application is the
 * first line of defence; the length check below only stops a writer that
 * bypasses the domain layer from storing a schedule that contradicts
 * `doses_per_day`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('source')->default('manual');
            $table->string('name');
            $table->string('dosage')->nullable();
            $table->string('form');
            $table->string('route')->nullable();
            $table->smallInteger('doses_per_day');
            $table->jsonb('schedule_times');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('prescribed_by')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status')->default('active');
            $table->text('discontinued_reason')->nullable();
            $table->timestampTz('discontinued_at')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_medication_plans_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_medication_plans_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('recorded_by', 'fk_medication_plans_recorded_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            // Target for the composite plan FK used by medication_records. It
            // prevents a record from pointing at a plan in another tenant.
            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_medication_plans_id_tenant_workspace');
            $table->index(['tenant_id', 'workspace_id'], 'ix_medication_plans_tenant_workspace');
            $table->index(['profile_id', 'status'], 'ix_medication_plans_profile_status');
            $table->index(['profile_id', 'starts_on'], 'ix_medication_plans_profile_starts_on');
        });

        DB::statement(
            'alter table medication_plans add constraint chk_medication_plans_form '
            ."check (form in ('tablet', 'capsule', 'liquid', 'injection', 'patch', 'inhaler', 'other'))"
        );
        DB::statement(
            'alter table medication_plans add constraint chk_medication_plans_route '
            ."check (route is null or route in ('oral', 'topical', 'injection', 'inhalation', 'other'))"
        );
        DB::statement(
            'alter table medication_plans add constraint chk_medication_plans_doses_per_day '
            .'check (doses_per_day between 1 and 12)'
        );
        DB::statement(
            'alter table medication_plans add constraint chk_medication_plans_window '
            .'check (ends_on is null or ends_on >= starts_on)'
        );
        DB::statement(
            'alter table medication_plans add constraint chk_medication_plans_status '
            ."check (status in ('active', 'discontinued'))"
        );
        DB::statement(
            'alter table medication_plans add constraint chk_medication_plans_schedule_times '
            .'check (jsonb_typeof(schedule_times) = \'array\' '
            .'and jsonb_array_length(schedule_times) = doses_per_day)'
        );

        DB::statement('alter table medication_plans enable row level security');
        DB::statement('alter table medication_plans force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on medication_plans
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on medication_plans');
        DB::statement('alter table medication_plans no force row level security');
        DB::statement('alter table medication_plans disable row level security');

        Schema::dropIfExists('medication_plans');
    }
};
