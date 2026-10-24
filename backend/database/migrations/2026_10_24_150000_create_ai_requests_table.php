<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_requests — one metadata-only row per gateway attempt
 * (`.ai/08-AI-GATEWAY.md` section 2, AIG-007 / N-02).
 *
 * This table is deliberately incapable of storing prompt text, model output or
 * any health value. `record_refs` contains identifiers only (`type`, `id`) so a
 * summary can be traced back to the records it used without copying them out of
 * the health tables. `context_hash` is a sha256 of the scrubbed envelope, which
 * makes a run reproducible without retaining the envelope itself.
 *
 * Standard tenancy applies: composite workspace/profile FKs (ADR-013), forced
 * RLS (ADR-007) and a UUIDv7 primary key (ADR-008).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            // Nullable for a future workspace-level call; a profile summary
            // always sets it.
            $table->uuid('profile_id')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->string('purpose', 16);
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->integer('cost_minor')->nullable();
            // [{type, id}] only. Never content.
            $table->jsonb('record_refs')->default('[]');
            $table->string('context_hash', 64);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_ai_requests_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();

            // PostgreSQL skips a composite FK when any key column is NULL,
            // which is exactly the workspace-level (profile_id = null) case.
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_ai_requests_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();

            $table->foreign('actor_id', 'fk_ai_requests_actor')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_ai_requests_tenant_workspace');
            $table->index(['profile_id', 'created_at'], 'ix_ai_requests_profile_created');
            $table->index(['workspace_id', 'actor_id', 'created_at'], 'ix_ai_requests_actor_created');
        });

        DB::statement(
            'alter table ai_requests add constraint chk_ai_requests_purpose '
            ."check (purpose in ('summary', 'draft', 'ocr'))"
        );
        DB::statement(
            'alter table ai_requests add constraint chk_ai_requests_status '
            ."check (status in ('succeeded', 'failed', 'refused', 'disabled'))"
        );
        DB::statement(
            'alter table ai_requests add constraint chk_ai_requests_record_refs '
            ."check (jsonb_typeof(record_refs) = 'array')"
        );
        DB::statement(
            'alter table ai_requests add constraint chk_ai_requests_context_hash '
            ."check (context_hash ~ '^[0-9a-f]{64}$')"
        );
        DB::statement(
            'alter table ai_requests add constraint chk_ai_requests_latency '
            .'check (latency_ms >= 0)'
        );

        DB::statement('alter table ai_requests enable row level security');
        DB::statement('alter table ai_requests force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on ai_requests
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
        DB::statement('drop policy if exists tenant_isolation on ai_requests');
        DB::statement('alter table ai_requests no force row level security');
        DB::statement('alter table ai_requests disable row level security');

        Schema::dropIfExists('ai_requests');
    }
};
