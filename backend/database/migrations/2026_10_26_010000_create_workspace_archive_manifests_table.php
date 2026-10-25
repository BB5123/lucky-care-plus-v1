<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `workspace_archive_manifests` - a verifiable inventory of an archived account
 * (P4a, RETENTION-MODEL.md §6).
 *
 * The archive store itself (an encrypted copy outside the active tables) is P4b.
 * This table is the first honest step: when a workspace enters `archived`, the
 * system records *what it held* - one count per table plus a sha256 over the
 * canonical JSON - so a restore, a support request or a later deletion can prove
 * what existed without loading the data.
 *
 * The manifest carries counts and a hash only. No names, no values, no free text
 * (N-02): a manifest that leaked a person's data would defeat the archive
 * exclusion it exists to support.
 *
 * A workspace can be archived more than once (restore, then lapse again), so
 * there is no unique key on the workspace: each transition appends a manifest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_archive_manifests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->timestampTz('archived_at');
            $table->jsonb('counts')->default('{}');
            $table->string('sha256', 64);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_archive_manifests_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();

            $table->index(['workspace_id', 'archived_at'], 'ix_archive_manifests_workspace_time');
        });

        DB::statement('alter table workspace_archive_manifests enable row level security');
        DB::statement('alter table workspace_archive_manifests force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on workspace_archive_manifests
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
        DB::statement('drop policy if exists tenant_isolation on workspace_archive_manifests');
        DB::statement('alter table workspace_archive_manifests no force row level security');
        DB::statement('alter table workspace_archive_manifests disable row level security');

        Schema::dropIfExists('workspace_archive_manifests');
    }
};
