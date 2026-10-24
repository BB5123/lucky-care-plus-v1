<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * data_exports — the pointer and lifecycle of one PDPA data-export job.
 *
 * The row never holds health data. It holds only the opaque object key, the
 * byte size, the lifecycle status and timestamps. The exported JSON lives on
 * the private object store, addressed by `exports/{tenant_uuid}/{export_uuid}.json`
 * (no profile id, no person name, no document name).
 *
 * Standard tenancy pattern: composite workspace/profile FKs (ADR-013), forced
 * RLS (ADR-007) and a UUIDv7 primary key (ADR-008). `object_key` is encrypted
 * by the model because it is a locator for a file that contains health data and
 * must not be readable from a database dump alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 16)->default('queued');
            $table->string('format', 16)->default('json');
            // Ciphertext at rest: the path is not useful without the app key.
            $table->text('object_key')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_data_exports_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_data_exports_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('requested_by', 'fk_data_exports_requested_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            // Composite target for future references that must stay inside one
            // tenant/workspace, mirroring the other tenant-scoped tables.
            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_data_exports_id_tenant_workspace');
            $table->index(['tenant_id', 'workspace_id'], 'ix_data_exports_tenant_workspace');
            // The 24-hour rate check reads the newest row for one profile.
            $table->index(['profile_id', 'requested_at'], 'ix_data_exports_profile_requested');
            $table->index(['workspace_id', 'status'], 'ix_data_exports_workspace_status');
        });

        DB::statement(
            'alter table data_exports add constraint chk_data_exports_status '
            ."check (status in ('queued', 'building', 'ready', 'failed', 'expired'))"
        );
        DB::statement(
            'alter table data_exports add constraint chk_data_exports_format '
            ."check (format in ('json'))"
        );
        DB::statement(
            'alter table data_exports add constraint chk_data_exports_byte_size '
            .'check (byte_size is null or byte_size > 0)'
        );

        DB::statement('alter table data_exports enable row level security');
        DB::statement('alter table data_exports force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on data_exports
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
                with check (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on data_exports');
        DB::statement('alter table data_exports no force row level security');
        DB::statement('alter table data_exports disable row level security');

        Schema::dropIfExists('data_exports');
    }
};
