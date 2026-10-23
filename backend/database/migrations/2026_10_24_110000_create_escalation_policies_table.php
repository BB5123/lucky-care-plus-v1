<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * escalation_policies — the per-workspace escalation ladder (`.ai/05 §6`).
 *
 * One row per level (1–3). The ladder is a workspace setting, not a task
 * attribute: `care_tasks.escalation_level` only records how far this task has
 * already climbed, while the policy rows decide when the next level fires and
 * who gets notified.
 *
 * Standard tenancy applies: composite workspace FK (ADR-013), UUIDv7 PK
 * (ADR-008) and forced RLS (ADR-007). A workspace with no rows escalates
 * nothing, which is the fail-safe default (F-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->smallInteger('level');
            // Minutes after the task's due_at when this level fires.
            $table->integer('after_minutes');
            $table->boolean('notify_assignee')->default(true);
            // Membership roles, e.g. ["workspace_admin"]. Never user ids:
            // membership changes must be reflected without editing the policy.
            $table->jsonb('notify_roles')->default('[]');
            $table->string('action')->default('notify');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_escalation_policies_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();

            // Exactly one policy per level inside a workspace.
            $table->unique(['workspace_id', 'level'], 'uq_escalation_policies_workspace_level');
            $table->index(['tenant_id', 'workspace_id'], 'ix_escalation_policies_tenant_workspace');
            $table->index(['workspace_id', 'is_active'], 'ix_escalation_policies_workspace_active');
        });

        DB::statement(
            'alter table escalation_policies add constraint chk_escalation_policies_level '
            .'check (level between 1 and 3)'
        );
        DB::statement(
            'alter table escalation_policies add constraint chk_escalation_policies_after_minutes '
            .'check (after_minutes >= 5)'
        );
        DB::statement(
            'alter table escalation_policies add constraint chk_escalation_policies_action '
            ."check (action in ('notify', 'reassign_to_admin'))"
        );
        DB::statement(
            'alter table escalation_policies add constraint chk_escalation_policies_notify_roles '
            ."check (jsonb_typeof(notify_roles) = 'array')"
        );

        DB::statement('alter table escalation_policies enable row level security');
        DB::statement('alter table escalation_policies force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on escalation_policies
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists tenant_isolation on escalation_policies');
        DB::statement('alter table escalation_policies no force row level security');
        DB::statement('alter table escalation_policies disable row level security');

        Schema::dropIfExists('escalation_policies');
    }
};
