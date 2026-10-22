<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * medication_records — one row per reported dose event (taken / skipped / missed).
 *
 * A `taken` row is self-reported: it is not clinical proof of ingestion
 * (`.ai/05-CARE-COORDINATION.md`, `.ai/02-DOMAIN-MODEL.md` §5 C8). The row only
 * records what a caregiver reported, never a verified administration.
 *
 * The partial unique index stops the same dose slot from being confirmed twice
 * while still allowing several unslotted (`scheduled_for is null`) events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->uuid('plan_id');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('source')->default('manual');
            $table->string('status');
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('recorded_at');
            $table->text('note')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_medication_records_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_medication_records_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign(['plan_id', 'tenant_id', 'workspace_id'], 'fk_medication_records_plan')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('medication_plans')
                ->cascadeOnDelete();
            $table->foreign('recorded_by', 'fk_medication_records_recorded_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_medication_records_tenant_workspace');
            $table->index(['plan_id'], 'ix_medication_records_plan');
        });

        // Descending index for the profile timeline (`.ai/06 §7`).
        DB::statement(
            'create index ix_medication_records_profile_recorded_at '
            .'on medication_records (profile_id, recorded_at desc)'
        );

        // One confirmation per dose slot; unslotted events are unrestricted.
        DB::statement(
            'create unique index uq_medication_records_plan_slot '
            .'on medication_records (plan_id, scheduled_for) where scheduled_for is not null'
        );

        DB::statement(
            'alter table medication_records add constraint chk_medication_records_status '
            ."check (status in ('taken', 'skipped', 'missed'))"
        );

        DB::statement('alter table medication_records enable row level security');
        DB::statement('alter table medication_records force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on medication_records
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on medication_records');
        DB::statement('alter table medication_records no force row level security');
        DB::statement('alter table medication_records disable row level security');

        DB::statement('drop index if exists uq_medication_records_plan_slot');
        DB::statement('drop index if exists ix_medication_records_profile_recorded_at');

        Schema::dropIfExists('medication_records');
    }
};
