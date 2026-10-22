<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * health_measurements — minimal Phase 0 shape for the retention/purge tests.
 *
 * Phase 1 will extend this table (typed readings, provenance, import source).
 * It is kept deliberately small here, with the standard tenant/workspace
 * columns, composite foreign keys, soft deletes (DM-R01) and forced RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_measurements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->string('type');
            $table->decimal('value', 14, 4);
            $table->string('unit');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('source')->default('manual');
            $table->integer('version')->default(1);
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_health_measurements_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_health_measurements_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('recorded_by', 'fk_health_measurements_recorded_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_health_measurements_tenant_workspace');
            $table->index(['profile_id', 'type', 'recorded_at'], 'ix_health_measurements_profile_type_time');
            $table->index('recorded_at', 'ix_health_measurements_recorded_at');
        });

        DB::statement('alter table health_measurements enable row level security');
        DB::statement('alter table health_measurements force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on health_measurements
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on health_measurements');
        DB::statement('alter table health_measurements no force row level security');
        DB::statement('alter table health_measurements disable row level security');

        Schema::dropIfExists('health_measurements');
    }
};
