<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * access_grants — the actual data permission for a profile (F-06, F-07).
 *
 * One grant = one profile (CD-014). `resource_scope` and `allowed_actions`
 * are JSONB arrays of tokens from .ai/03-AUTHORIZATION.md §5 and
 * .ai/04-CONSENT-DELEGATION.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->unsignedBigInteger('granted_by');
            $table->unsignedBigInteger('grantee_user_id');
            $table->jsonb('resource_scope');
            $table->jsonb('allowed_actions');
            $table->string('purpose')->nullable();
            $table->timestampTz('valid_from');
            $table->timestampTz('expires_at');
            $table->string('status')->default('pending');
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->jsonb('constraints')->default('{}');
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_access_grants_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_access_grants_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('granted_by', 'fk_access_grants_granted_by')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('grantee_user_id', 'fk_access_grants_grantee_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('revoked_by', 'fk_access_grants_revoked_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_access_grants_tenant_workspace');
            $table->index(['profile_id', 'grantee_user_id', 'status'], 'ix_access_grants_profile_grantee_status');
            $table->index('grantee_user_id', 'ix_access_grants_grantee_user');
        });

        DB::statement(
            'alter table access_grants add constraint chk_access_grants_status '
            ."check (status in ('pending', 'active', 'expired', 'revoked'))"
        );
        // DM-005: a grant can never expire before (or at) it becomes valid.
        DB::statement(
            'alter table access_grants add constraint chk_access_grants_validity '
            .'check (expires_at > valid_from)'
        );

        DB::statement('alter table access_grants enable row level security');
        DB::statement('alter table access_grants force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on access_grants
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on access_grants');
        DB::statement('alter table access_grants no force row level security');
        DB::statement('alter table access_grants disable row level security');

        Schema::dropIfExists('access_grants');
    }
};
