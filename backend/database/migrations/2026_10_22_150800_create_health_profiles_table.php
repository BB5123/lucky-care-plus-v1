<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * health_profiles — the profile an access grant points at.
 *
 * One profile per person (1:1). A dependent person's profile records the
 * person who manages it in `managed_by_person_id` (ADR-009, CD-020).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('person_id');
            $table->uuid('managed_by_person_id')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_health_profiles_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['person_id', 'tenant_id', 'workspace_id'], 'fk_health_profiles_person')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons')
                ->cascadeOnDelete();
            // No ON DELETE action: the manager must be re-assigned explicitly,
            // never silently dropped by deleting the manager person.
            $table->foreign(['managed_by_person_id', 'tenant_id', 'workspace_id'], 'fk_health_profiles_managed_by')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons');

            $table->unique('person_id', 'uq_health_profiles_person');
            // Target for the composite child FKs (care_relationships,
            // access_grants, consent_records).
            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_health_profiles_id_tenant_workspace');
            $table->index(['tenant_id', 'workspace_id'], 'ix_health_profiles_tenant_workspace');
        });

        DB::statement('alter table health_profiles enable row level security');
        DB::statement('alter table health_profiles force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on health_profiles
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on health_profiles');
        DB::statement('alter table health_profiles no force row level security');
        DB::statement('alter table health_profiles disable row level security');

        Schema::dropIfExists('health_profiles');
    }
};
