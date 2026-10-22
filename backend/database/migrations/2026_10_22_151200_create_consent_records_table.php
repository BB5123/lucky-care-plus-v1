<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * consent_records — versioned, append-only consent (CD-001..006, DM-006).
 *
 * Rows are immutable. A new consent is a new row; the only permitted UPDATE
 * is the withdrawal transition (`withdrawn_at` and optionally
 * `superseded_by_id`). DELETE is never allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->string('consent_type');
            $table->string('purpose');
            $table->jsonb('scope');
            $table->string('policy_version');
            $table->uuid('consenting_subject_id');
            $table->string('legal_basis_type');
            $table->timestampTz('granted_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_consent_records_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_consent_records_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign(['consenting_subject_id', 'tenant_id', 'workspace_id'], 'fk_consent_records_subject')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons')
                ->cascadeOnDelete();

            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_consent_records_id_tenant_workspace');
            $table->foreign(['superseded_by_id', 'tenant_id', 'workspace_id'], 'fk_consent_records_superseded_by')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('consent_records');
            $table->index(['tenant_id', 'workspace_id'], 'ix_consent_records_tenant_workspace');
            $table->index(['profile_id', 'consent_type'], 'ix_consent_records_profile_type');
        });

        DB::statement(
            'alter table consent_records add constraint chk_consent_records_type '
            ."check (consent_type in ('health_processing', 'ai_processing', 'data_export', 'third_party_share'))"
        );
        DB::statement(
            'alter table consent_records add constraint chk_consent_records_legal_basis '
            ."check (legal_basis_type in ('consent', 'vital_interest', 'contract', 'legal_obligation'))"
        );

        // Shared append-only guard for tables where no UPDATE/DELETE is allowed
        // at all. Kept installed across partial rollbacks because several
        // tables depend on it.
        DB::statement(<<<'SQL'
            create or replace function forbid_mutation() returns trigger as $$
            begin
                raise exception 'append-only table: % rows cannot be modified or deleted', TG_TABLE_NAME
                    using errcode = '55000';
            end;
            $$ language plpgsql
        SQL);

        // consent_records allows exactly one transition: open -> withdrawn.
        DB::statement(<<<'SQL'
            create or replace function consent_records_guard_update() returns trigger as $$
            begin
                if old.withdrawn_at is not null then
                    raise exception 'consent_records rows are append-only: consent is already withdrawn'
                        using errcode = '55000';
                end if;

                if new.withdrawn_at is null then
                    raise exception 'consent_records rows are append-only: only withdrawn_at/superseded_by_id may change'
                        using errcode = '55000';
                end if;

                if (to_jsonb(new) - 'withdrawn_at' - 'superseded_by_id')
                    is distinct from (to_jsonb(old) - 'withdrawn_at' - 'superseded_by_id') then
                    raise exception 'consent_records rows are append-only: only withdrawn_at/superseded_by_id may change'
                        using errcode = '55000';
                end if;

                return new;
            end;
            $$ language plpgsql
        SQL);

        DB::statement(
            'create trigger consent_records_no_update before update on consent_records '
            .'for each row execute function consent_records_guard_update()'
        );
        DB::statement(
            'create trigger consent_records_no_delete before delete on consent_records '
            .'for each row execute function forbid_mutation()'
        );

        DB::statement('alter table consent_records enable row level security');
        DB::statement('alter table consent_records force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on consent_records
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on consent_records');
        DB::statement('alter table consent_records no force row level security');
        DB::statement('alter table consent_records disable row level security');

        DB::statement('drop trigger if exists consent_records_no_update on consent_records');
        DB::statement('drop trigger if exists consent_records_no_delete on consent_records');

        Schema::dropIfExists('consent_records');

        DB::statement('drop function if exists consent_records_guard_update()');
    }
};
