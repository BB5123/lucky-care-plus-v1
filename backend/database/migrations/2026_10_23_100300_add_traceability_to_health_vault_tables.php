<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the provenance columns that `.ai/06-DATA-MODEL.md` §3 requires for every
 * health-data table (`recorded_by`, `source`) plus soft deletes so deletion
 * stays non-destructive (DM-R01).
 *
 * `health_measurements` already had them; `health_conditions` and `allergies`
 * were created without, which broke traceability: without `recorded_by` a row
 * cannot answer "who recorded this", and without `source` downstream code
 * (timeline, AI context, doctor brief) cannot tell a user entry from a device
 * or task entry.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['health_conditions', 'allergies'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // `users.id` is a bigint in this schema (see health_measurements.recorded_by),
                // so the provenance column must be bigint for the FK to be enforceable.
                $blueprint->foreignId('recorded_by')->nullable()->after('profile_id');
                $blueprint->string('source')->default('manual')->after('recorded_by');
                $blueprint->timestampTz('deleted_at')->nullable()->after('version');

                $blueprint->foreign('recorded_by', "fk_{$blueprint->getTable()}_recorded_by")
                    ->references('id')->on('users')
                    ->nullOnDelete();

                $blueprint->index(
                    ['profile_id', 'deleted_at'],
                    "ix_{$blueprint->getTable()}_profile_deleted",
                );
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex("ix_{$blueprint->getTable()}_profile_deleted");
                $blueprint->dropForeign("fk_{$blueprint->getTable()}_recorded_by");
                $blueprint->dropColumn(['recorded_by', 'source', 'deleted_at']);
            });
        }
    }
};
