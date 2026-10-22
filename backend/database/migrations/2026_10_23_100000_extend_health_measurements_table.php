<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Health Vault measurement fields.
 *
 * Adds the typed companion columns (diastolic value, measurement context,
 * encrypted free-text note) and replaces the Phase 0 graph index with the
 * descending form required by `.ai/06-DATA-MODEL.md §7`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_measurements', function (Blueprint $table) {
            $table->decimal('secondary_value', 10, 2)->nullable();
            $table->string('context')->nullable();
            $table->text('note')->nullable();
        });

        // Context is a closed vocabulary; the application validates it first
        // and the database rejects anything that bypasses the domain layer.
        DB::statement(
            'alter table health_measurements add constraint chk_health_measurements_context '
            ."check (context is null or context in ('fasting', 'postprandial', 'random', 'morning', 'evening'))"
        );

        DB::statement('drop index if exists ix_health_measurements_profile_type_time');
        DB::statement(
            'create index ix_health_measurements_profile_type_time_desc '
            .'on health_measurements (profile_id, type, recorded_at desc)'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists ix_health_measurements_profile_type_time_desc');
        DB::statement(
            'create index ix_health_measurements_profile_type_time '
            .'on health_measurements (profile_id, type, recorded_at)'
        );

        DB::statement('alter table health_measurements drop constraint if exists chk_health_measurements_context');

        Schema::table('health_measurements', function (Blueprint $table) {
            $table->dropColumn(['secondary_value', 'context', 'note']);
        });
    }
};
