<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * delegations — legal authority of a representative over a person (ADR-015).
 *
 * Legal authority is NOT data access: a verified delegation still needs an
 * access_grant before the representative can read health data (F-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('represented_person_id');
            $table->unsignedBigInteger('representative_user_id');
            $table->string('level');
            // Placeholder until the `documents` table exists. Evidence will
            // become a FK to documents(id) with category = legal_authority
            // (CD-023); the raw reference never points at a public URL.
            $table->string('evidence_document_id')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('expires_at');
            $table->string('status')->default('pending');
            $table->text('revoked_reason')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_delegations_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['represented_person_id', 'tenant_id', 'workspace_id'], 'fk_delegations_represented_person')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons')
                ->cascadeOnDelete();
            $table->foreign('representative_user_id', 'fk_delegations_representative_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('verified_by', 'fk_delegations_verified_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_delegations_tenant_workspace');
            $table->index(['represented_person_id', 'status'], 'ix_delegations_person_status');
        });

        DB::statement(
            'alter table delegations add constraint chk_delegations_level '
            ."check (level in ('L1', 'L2', 'L3'))"
        );
        DB::statement(
            'alter table delegations add constraint chk_delegations_status '
            ."check (status in ('pending', 'verified', 'rejected', 'expired', 'revoked'))"
        );

        DB::statement('alter table delegations enable row level security');
        DB::statement('alter table delegations force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on delegations
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on delegations');
        DB::statement('alter table delegations no force row level security');
        DB::statement('alter table delegations disable row level security');

        Schema::dropIfExists('delegations');
    }
};
