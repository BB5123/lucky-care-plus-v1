<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * allergies — permitted allergy records attached to a profile.
 *
 * Same tenancy pattern as the other health tables: composite workspace FK,
 * composite profile FK, forced RLS, UUIDv7 PK and optimistic locking version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allergies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->string('substance');
            $table->string('reaction')->nullable();
            $table->string('severity');
            $table->date('noted_at')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_allergies_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_allergies_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_allergies_tenant_workspace');
            $table->index(['profile_id', 'severity', 'created_at'], 'ix_allergies_profile_severity');
        });

        DB::statement(
            'alter table allergies add constraint chk_allergies_severity '
            ."check (severity in ('mild', 'moderate', 'severe'))"
        );

        DB::statement('alter table allergies enable row level security');
        DB::statement('alter table allergies force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on allergies
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on allergies');
        DB::statement('alter table allergies no force row level security');
        DB::statement('alter table allergies disable row level security');

        Schema::dropIfExists('allergies');
    }
};
