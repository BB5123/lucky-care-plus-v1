<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * timeline_entries — the per-profile "what happened, in order" read model.
 *
 * It is a projection, never a source of truth (CRM-011): every row can be
 * rebuilt from the owning module's tables. It stores a reference plus a
 * translation key only (DM-007); the source value, diagnosis, notes, title or
 * any other free text is deliberately absent.
 *
 * Same tenancy pattern as every other health table: composite workspace and
 * profile FKs (ADR-013), forced RLS (ADR-007) and a UUIDv7 primary key
 * (ADR-008). No soft delete: derived data is either projected or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->timestampTz('occurred_at');
            $table->string('type', 32);
            $table->string('ref_type', 64);
            $table->uuid('ref_id');
            $table->string('summary_key', 120);
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_timeline_entries_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_timeline_entries_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();

            // Re-projection and a retried observer cannot duplicate an entry.
            $table->unique(['ref_type', 'ref_id', 'type'], 'uq_timeline_entries_source');
        });

        DB::statement(
            'alter table timeline_entries add constraint chk_timeline_entries_type '
            ."check (type in ('measurement', 'condition', 'allergy', 'medication_plan', "
            ."'medication_record', 'care_task', 'appointment'))"
        );
        DB::statement(
            'alter table timeline_entries add constraint chk_timeline_entries_metadata '
            ."check (jsonb_typeof(metadata) = 'object')"
        );

        // The endpoint always reads one profile newest-first, and the rebuild /
        // rebuild cleanup scans one workspace at a time.
        DB::statement(
            'create index ix_timeline_entries_profile_occurred '
            .'on timeline_entries (profile_id, occurred_at desc)'
        );
        DB::statement(
            'create index ix_timeline_entries_workspace_occurred '
            .'on timeline_entries (workspace_id, occurred_at desc)'
        );

        DB::statement('alter table timeline_entries enable row level security');
        DB::statement('alter table timeline_entries force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on timeline_entries
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on timeline_entries');
        DB::statement('alter table timeline_entries no force row level security');
        DB::statement('alter table timeline_entries disable row level security');

        Schema::dropIfExists('timeline_entries');
    }
};
