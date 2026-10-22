<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * notification_deliveries — the delivery ledger for every channel attempt
 * (`.ai/06-DATA-MODEL.md §3`, `§7`, `§8.1`).
 *
 * Tenant/workspace are nullable because an account-level notification has no
 * workspace. When both are set they are enforced by the standard composite FK
 * (ADR-013) and by RLS (ADR-007). The RLS policy additionally lets a user see
 * only their own rows through the transaction-local `app.user_id` setting;
 * the query layer still filters by `recipient_user_id` (defence in depth), so
 * two members of the same workspace can never read each other's deliveries.
 *
 * `params` is a JSONB object of translation placeholders only. The dispatcher
 * rejects PHI-shaped keys before an insert can happen (N-02); the database
 * constraint keeps the shape (object) but not the semantics.
 *
 * Retention (`.ai/06 §8.1`): payload for 90 days, metadata for 24 months.
 * That split is enforced by the retention jobs, not by a schema deletion rule,
 * because `read_at`/`status` are operational metadata that outlive `params`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id');
            $table->string('channel', 16);
            $table->string('template_key', 96);
            $table->jsonb('params')->default('{}');
            $table->string('status', 16);
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->timestampsTz();

            // Nullable composite FK: PostgreSQL skips the check when any
            // column is NULL, which is exactly the non-workspace case.
            $table->foreign(['workspace_id', 'tenant_id'], 'fk_notification_deliveries_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('recipient_user_id', 'fk_notification_deliveries_recipient')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_notification_deliveries_tenant_workspace');
            $table->index(['recipient_user_id', 'created_at'], 'ix_notification_deliveries_recipient_created');
            $table->index(['recipient_user_id', 'read_at'], 'ix_notification_deliveries_recipient_read');
            $table->index(['status', 'scheduled_for'], 'ix_notification_deliveries_status_scheduled');
            $table->index(['subject_type', 'subject_id'], 'ix_notification_deliveries_subject');
        });

        DB::statement(
            'alter table notification_deliveries add constraint chk_notification_deliveries_channel '
            ."check (channel in ('in_app', 'push', 'line'))"
        );
        DB::statement(
            'alter table notification_deliveries add constraint chk_notification_deliveries_status '
            ."check (status in ('queued', 'sent', 'failed', 'skipped'))"
        );
        DB::statement(
            'alter table notification_deliveries add constraint chk_notification_deliveries_attempts '
            .'check (attempts >= 0)'
        );
        DB::statement(
            'alter table notification_deliveries add constraint chk_notification_deliveries_params '
            ."check (jsonb_typeof(params) = 'object')"
        );
        DB::statement(
            'alter table notification_deliveries add constraint chk_notification_deliveries_scope '
            .'check (workspace_id is null or tenant_id is not null)'
        );

        DB::statement('alter table notification_deliveries enable row level security');
        DB::statement('alter table notification_deliveries force row level security');

        // Reads are OWNER-ONLY, plus an explicit system window used by the
        // dispatcher (which has no acting user). A single OR-ed policy that
        // also matched on tenant/workspace would let any member of a workspace
        // read every other member's delivery rows as soon as workspace context
        // is set, which defeats RLS as a backstop.
        DB::statement(<<<'SQL'
            create policy delivery_owner_read on notification_deliveries
                for select
                using (
                    recipient_user_id = nullif(current_setting('app.user_id', true), '')::bigint
                    or coalesce(current_setting('app.notification_system', true), '') = 'on'
                )
        SQL);

        // Mutating your own delivery (marking it read) is allowed; the status
        // transition itself is validated in the application layer. The
        // dispatcher may update any row while its system window is open.
        DB::statement(<<<'SQL'
            create policy delivery_owner_update on notification_deliveries
                for update
                using (
                    recipient_user_id = nullif(current_setting('app.user_id', true), '')::bigint
                    or coalesce(current_setting('app.notification_system', true), '') = 'on'
                )
        SQL);

        // The dispatcher writes for many recipients inside a workspace context.
        // Account-level rows (no tenant) are written for the acting user only.
        DB::statement(<<<'SQL'
            create policy delivery_insert on notification_deliveries
                for insert
                with check (
                    (
                        tenant_id is not null
                        and tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                        and (
                            workspace_id is null
                            or workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                        )
                    )
                    or (
                        tenant_id is null
                        and recipient_user_id = nullif(current_setting('app.user_id', true), '')::bigint
                    )
                )
        SQL);

        // Only the retention job may delete deliveries, and only while it sets
        // the same guard flag used for append-only operational tables.
        DB::statement(<<<'SQL'
            create policy delivery_retention_delete on notification_deliveries
                for delete
                using (coalesce(current_setting('app.retention_purge', true), '') = 'on')
        SQL);
    }

    public function down(): void
    {
        // Policies are dropped before the table (and before disabling RLS) so
        // a partial rollback never leaves a dangling policy behind.
        foreach ([
            'delivery_owner_read',
            'delivery_owner_update',
            'delivery_insert',
            'delivery_retention_delete',
        ] as $policy) {
            DB::statement("drop policy if exists {$policy} on notification_deliveries");
        }
        DB::statement('alter table notification_deliveries no force row level security');
        DB::statement('alter table notification_deliveries disable row level security');

        Schema::dropIfExists('notification_deliveries');
    }
};
