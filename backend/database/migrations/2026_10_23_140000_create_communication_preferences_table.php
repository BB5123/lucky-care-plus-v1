<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * communication_preferences — one row per user (`.ai/06-DATA-MODEL.md §9`,
 * `docs/baseline-v1.0.0/PERMISSION_CATALOG.md §4.11`).
 *
 * Deliberately **not** tenant-scoped: a person's notification choices follow the
 * person across workspaces, exactly like the `/me` endpoints that manage them.
 * No health data is stored here and no RLS is needed because there is no
 * tenant/workspace boundary to isolate; access is guarded by the per-user
 * unique key plus the self-membership authorization in the Authorizer.
 *
 * Changing these values is explicitly **not** a consent action (ABD-016), so
 * this table has no relationship to `consent_records`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('locale', 2)->default('th');
            $table->jsonb('channels')->default('{"in_app": true, "push": true, "line": false}');
            $table->jsonb('quiet_hours')->nullable();
            $table->string('digest', 16)->default('immediate');
            $table->timestampsTz();

            $table->foreign('user_id', 'fk_communication_preferences_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });

        DB::statement(
            'alter table communication_preferences add constraint chk_communication_preferences_locale '
            ."check (locale in ('th', 'en'))"
        );
        DB::statement(
            'alter table communication_preferences add constraint chk_communication_preferences_digest '
            ."check (digest in ('immediate', 'daily'))"
        );
        DB::statement(
            'alter table communication_preferences add constraint chk_communication_preferences_channels '
            ."check (jsonb_typeof(channels) = 'object')"
        );
        DB::statement(
            'alter table communication_preferences add constraint chk_communication_preferences_quiet_hours '
            ."check (quiet_hours is null or jsonb_typeof(quiet_hours) = 'object')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_preferences');
    }
};
