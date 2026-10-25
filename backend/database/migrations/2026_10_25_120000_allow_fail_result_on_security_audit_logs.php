<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CR-005-FIX: allow `result = 'fail'` in the security ledger.
 *
 * The review endpoint records a failed corpus write as `result=fail` with
 * `reason_code=corpus_readonly`, distinct from a denied authorization decision
 * (`deny`) and from an internal error (`error`). The original constraint only
 * accepted `allow|deny|error`, so the row could not be appended and the failure
 * was invisible. The audit trail is immutable, therefore a downgrade keeps the
 * wider check rather than rewriting or deleting `fail` rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'alter table security_audit_logs drop constraint if exists chk_security_audit_logs_result'
        );
        DB::statement(
            'alter table security_audit_logs add constraint chk_security_audit_logs_result '
            ."check (result in ('allow', 'deny', 'error', 'fail'))"
        );
    }

    public function down(): void
    {
        // Narrowing the check would reject `fail` rows that the append-only
        // trigger forbids us from updating or deleting. A downgrade therefore
        // leaves the wider constraint in place; the application no longer
        // writes `fail` after the code is rolled back.
    }
};
