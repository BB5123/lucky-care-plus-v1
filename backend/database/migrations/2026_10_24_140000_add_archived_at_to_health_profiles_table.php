<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive state for health profiles (baseline `health_profile.archive`).
 *
 * The permission catalog reserves `health_profile.archive` ("Archive a
 * profile where policy permits"), but the profile table had no column to
 * represent that state, so a read model could not tell a live profile from an
 * archived one. The new profile list must not offer an archived profile as a
 * health-entry point.
 *
 * A nullable timestamp is used rather than a boolean so the archive event
 * keeps its own time (audit/retention) and unarchiving is a `null` write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_profiles', function (Blueprint $table) {
            $table->timestampTz('archived_at')->nullable()->after('version');

            $table->index(
                ['workspace_id', 'archived_at'],
                'ix_health_profiles_workspace_archived',
            );
        });
    }

    public function down(): void
    {
        Schema::table('health_profiles', function (Blueprint $table) {
            $table->dropIndex('ix_health_profiles_workspace_archived');
            $table->dropColumn('archived_at');
        });
    }
};
