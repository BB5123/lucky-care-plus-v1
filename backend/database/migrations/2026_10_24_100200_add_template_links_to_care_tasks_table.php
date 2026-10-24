<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a materialised occurrence back to its template (P2.1).
 *
 * `occurrence_key` is `Y-m-d\TH:i` in the template timezone. The unique
 * `(template_id, occurrence_key)` index is the database backstop that makes
 * materialisation idempotent even when two runs overlap (RC-001/RC-002);
 * application code also writes a `template_id:occurrence_key` idempotency key.
 *
 * A soft-deleted occurrence still occupies its key, which is correct: the
 * materialiser must not resurrect an occurrence the user cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_tasks', function (Blueprint $table) {
            $table->string('occurrence_key')->nullable()->after('template_id');

            $table->foreign('template_id', 'fk_care_tasks_template')
                ->references('id')->on('care_task_templates')
                ->nullOnDelete();

            $table->unique(['template_id', 'occurrence_key'], 'uq_care_tasks_template_occurrence');
        });
    }

    public function down(): void
    {
        Schema::table('care_tasks', function (Blueprint $table) {
            $table->dropUnique('uq_care_tasks_template_occurrence');
            $table->dropForeign('fk_care_tasks_template');
            $table->dropColumn('occurrence_key');
        });
    }
};
