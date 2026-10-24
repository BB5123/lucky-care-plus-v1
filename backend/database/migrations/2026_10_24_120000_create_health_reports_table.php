<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * health_reports — frozen clinical snapshots (`.ai/05 §9`, DB-001..004).
 *
 * A row is an immutable snapshot: regenerating creates a new row, never an
 * in-place update, because a doctor may still be holding a link to an older
 * version. Tenancy follows the standard pattern: composite workspace/profile
 * FKs (ADR-013), forced RLS (ADR-007), UUIDv7 PK (ADR-008) and `version`.
 *
 * The payload column is the only place health data lives here. It is never
 * copied into logs, audit metadata, URLs or file names (N-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->string('kind')->default('doctor_brief');
            $table->unsignedBigInteger('generated_by');
            $table->date('period_from');
            $table->date('period_to');
            $table->jsonb('payload');
            $table->string('status')->default('active');
            $table->integer('version')->default(1);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_health_reports_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_health_reports_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('generated_by', 'fk_health_reports_generated_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            // Composite target for report_shares.
            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_health_reports_id_tenant_workspace');
            $table->index(['tenant_id', 'workspace_id'], 'ix_health_reports_tenant_workspace');
            $table->index(['profile_id', 'created_at'], 'ix_health_reports_profile_created');
        });

        DB::statement(
            'alter table health_reports add constraint chk_health_reports_kind '
            ."check (kind in ('doctor_brief'))"
        );
        DB::statement(
            'alter table health_reports add constraint chk_health_reports_status '
            ."check (status in ('active', 'revoked'))"
        );
        DB::statement(
            'alter table health_reports add constraint chk_health_reports_period_order '
            .'check (period_to >= period_from)'
        );
        DB::statement(
            'alter table health_reports add constraint chk_health_reports_period_length '
            .'check ((period_to - period_from) <= 180)'
        );

        DB::statement('alter table health_reports enable row level security');
        DB::statement('alter table health_reports force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on health_reports
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
        DB::statement('drop policy if exists tenant_isolation on health_reports');
        DB::statement('alter table health_reports no force row level security');
        DB::statement('alter table health_reports disable row level security');

        Schema::dropIfExists('health_reports');
    }
};
