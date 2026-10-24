<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK INV-1: invitations need a revocation state.
 *
 * The table has no status column and only `accepted_at`, so "not yet accepted"
 * alone cannot distinguish a live invitation from one the inviter cancelled.
 * Revocation is a soft state (not a delete) because the row is the audit trail
 * for a token that was issued, and a revoked token must still resolve to a
 * row that `InvitationService::accept()` refuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->timestampTz('revoked_at')->nullable();

            // The list endpoint reads pending rows for one workspace; this
            // composite index keeps that lookup specific.
            $table->index(
                ['tenant_id', 'workspace_id', 'revoked_at'],
                'ix_invitations_tenant_workspace_revoked',
            );
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex('ix_invitations_tenant_workspace_revoked');
            $table->dropColumn('revoked_at');
        });
    }
};
