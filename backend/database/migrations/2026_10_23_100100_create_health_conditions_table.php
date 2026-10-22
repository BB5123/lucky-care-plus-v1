<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * health_conditions — permitted medical conditions attached to a profile.
 *
 * Same tenancy pattern as every other health table: composite workspace FK
 * (ADR-013), composite profile FK, forced RLS (ADR-007), UUIDv7 PK (ADR-008),
 * and `version` for optimistic locking (DM-010).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->string('code', 16)->nullable();
            $table->string('name');
            $table->date('diagnosed_at')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_health_conditions_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_health_conditions_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_health_conditions_tenant_workspace');
            $table->index(['profile_id', 'status', 'created_at'], 'ix_health_conditions_profile_status');
        });

        DB::statement(
            'alter table health_conditions add constraint chk_health_conditions_status '
            ."check (status in ('active', 'resolved'))"
        );

        DB::statement('alter table health_conditions enable row level security');
        DB::statement('alter table health_conditions force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on health_conditions
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on health_conditions');
        DB::statement('alter table health_conditions no force row level security');
        DB::statement('alter table health_conditions disable row level security');

        Schema::dropIfExists('health_conditions');
    }
};
