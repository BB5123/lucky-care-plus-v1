<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_usage_counters — per actor/workspace/day quota ledger
 * (`.ai/08-AI-GATEWAY.md` section 4).
 *
 * The counter is metadata only: request count, token count and cost. It never
 * carries health data or prompt text. `actor_id` is nullable for a future
 * workspace/system caller, while an ordinary AI call always has an actor
 * (AIG-009).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->date('day');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('tokens')->default(0);
            $table->integer('cost_minor')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_ai_usage_counters_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();

            $table->foreign('actor_id', 'fk_ai_usage_counters_actor')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->unique(
                ['workspace_id', 'actor_id', 'day'],
                'uq_ai_usage_counters_workspace_actor_day',
            );
            $table->index(['tenant_id', 'workspace_id', 'day'], 'ix_ai_usage_counters_tenant_workspace_day');
        });

        DB::statement(
            'alter table ai_usage_counters add constraint chk_ai_usage_counters_requests '
            .'check (requests >= 0)'
        );
        DB::statement(
            'alter table ai_usage_counters add constraint chk_ai_usage_counters_tokens '
            .'check (tokens >= 0)'
        );
        DB::statement(
            'alter table ai_usage_counters add constraint chk_ai_usage_counters_cost '
            .'check (cost_minor >= 0)'
        );

        DB::statement('alter table ai_usage_counters enable row level security');
        DB::statement('alter table ai_usage_counters force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on ai_usage_counters
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
        DB::statement('drop policy if exists tenant_isolation on ai_usage_counters');
        DB::statement('alter table ai_usage_counters no force row level security');
        DB::statement('alter table ai_usage_counters disable row level security');

        Schema::dropIfExists('ai_usage_counters');
    }
};
