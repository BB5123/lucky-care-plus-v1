<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the retention lifecycle state to a workspace (P2, RETENTION-MODEL.md
 * section 2). The five states are per account, not per row: a family's whole
 * workspace lapses, is archived, is scheduled for deletion, and is deleted as
 * one unit.
 *
 * `retention_expires_at` is the deadline of the *current* state (when the next
 * arrow becomes due). `lapsed_at` records when the lapse started and is cleared
 * on restore. Both are set only by the server-side lifecycle service - they are
 * deliberately not mass-assignable (N-01).
 *
 * The check constraint is part of the spec, not a nicety: a typo in a state
 * name must fail the write instead of inventing a sixth state nobody handles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('retention_state', 20)->default('active');
            $table->timestampTz('retention_state_changed_at')->nullable();
            $table->timestampTz('retention_expires_at')->nullable();
            $table->timestampTz('lapsed_at')->nullable();

            // Every nightly advance scans for due rows; without this index that
            // scan is a full table walk on the largest tenant.
            $table->index(['retention_state', 'retention_expires_at'], 'ix_workspaces_retention_due');
        });

        DB::statement(
            'alter table workspaces add constraint chk_workspaces_retention_state '
            ."check (retention_state in ('active', 'read_only', 'archived', 'scheduled_deletion', 'deleted'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table workspaces drop constraint if exists chk_workspaces_retention_state');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropIndex('ix_workspaces_retention_due');
            $table->dropColumn([
                'retention_state',
                'retention_state_changed_at',
                'retention_expires_at',
                'lapsed_at',
            ]);
        });
    }
};
