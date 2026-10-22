<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * data_holds — legal holds that block every purge path (DM-R04 / ADR-016 §3).
 *
 * A hold is scoped to a workspace, optionally narrowed to one health profile.
 * `until = null` means indefinite. Only unreleased, non-expired holds count.
 *
 * `created_by` / `released_by` reference `users.id`, which is a bigint in this
 * schema (the task prose said "uuid"; the existing identity table dictates the
 * type so the foreign keys are actually enforceable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('until')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_data_holds_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_data_holds_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'fk_data_holds_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();
            $table->foreign('released_by', 'fk_data_holds_released_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_data_holds_tenant_workspace');
            $table->index(['workspace_id', 'profile_id', 'released_at'], 'ix_data_holds_active_lookup');
            $table->index('until', 'ix_data_holds_until');
        });

        DB::statement('alter table data_holds enable row level security');
        DB::statement('alter table data_holds force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on data_holds
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on data_holds');
        DB::statement('alter table data_holds no force row level security');
        DB::statement('alter table data_holds disable row level security');

        Schema::dropIfExists('data_holds');
    }
};
