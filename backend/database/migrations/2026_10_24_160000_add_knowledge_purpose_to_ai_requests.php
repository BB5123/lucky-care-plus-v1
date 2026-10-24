<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The knowledge-answer purpose (TASK G4-KB) is a new closed purpose value.
 *
 * The original check only allowed summary/draft/ocr. `purpose` is a closed
 * vocabulary, so widening it is an explicit schema change rather than a free
 * string; `down()` restores the original set (it fails if knowledge rows
 * exist, which is the intended fail-closed behaviour for a rollback).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table ai_requests drop constraint chk_ai_requests_purpose');
        DB::statement(
            'alter table ai_requests add constraint chk_ai_requests_purpose '
            ."check (purpose in ('summary', 'draft', 'ocr', 'knowledge'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table ai_requests drop constraint chk_ai_requests_purpose');
        DB::statement(
            'alter table ai_requests add constraint chk_ai_requests_purpose '
            ."check (purpose in ('summary', 'draft', 'ocr'))"
        );
    }
};
