<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * care_relationships — who helps care for a profile, and in which ring.
 *
 * A care relationship is social context only: it never grants data access
 * (F-04/F-05). Health data access is always an access_grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->uuid('person_id');
            $table->string('ring');
            $table->string('relationship_type');
            $table->string('status')->default('active');
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_care_relationships_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_care_relationships_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign(['person_id', 'tenant_id', 'workspace_id'], 'fk_care_relationships_person')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons')
                ->cascadeOnDelete();

            $table->unique(['profile_id', 'person_id'], 'uq_care_relationships_profile_person');
            $table->index(['tenant_id', 'workspace_id'], 'ix_care_relationships_tenant_workspace');
        });

        DB::statement(
            'alter table care_relationships add constraint chk_care_relationships_ring '
            ."check (ring in ('inner', 'support', 'professional'))"
        );
        DB::statement(
            'alter table care_relationships add constraint chk_care_relationships_status '
            ."check (status in ('active', 'inactive'))"
        );

        DB::statement('alter table care_relationships enable row level security');
        DB::statement('alter table care_relationships force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on care_relationships
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on care_relationships');
        DB::statement('alter table care_relationships no force row level security');
        DB::statement('alter table care_relationships disable row level security');

        Schema::dropIfExists('care_relationships');
    }
};
