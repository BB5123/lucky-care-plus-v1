<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links `task_evidence.document_id` to `medical_documents` without weakening
 * tenant isolation (ADR-013).
 *
 * `document_id` was reserved before the documents table existed and is
 * therefore FK-less. Any pre-existing value necessarily points at nothing, so
 * it is cleared before the constraint is installed. The composite FK proves
 * the evidence and the document live in the same tenant and workspace.
 *
 * PostgreSQL 15+ supports a column list on `ON DELETE SET NULL`; that is
 * required because tenant_id/workspace_id are NOT NULL and must keep pointing
 * at the evidence row while only `document_id` is cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_evidence')) {
            return;
        }

        // The table is new in this migration chain, so every legacy value is
        // dangling by definition. Never leave a value that would fail the FK.
        DB::table('task_evidence')
            ->whereNotNull('document_id')
            ->update(['document_id' => null]);

        DB::statement(
            'alter table task_evidence '
            .'add constraint fk_task_evidence_document '
            .'foreign key (document_id, tenant_id, workspace_id) '
            .'references medical_documents (id, tenant_id, workspace_id) '
            .'on delete set null (document_id)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('task_evidence')) {
            return;
        }

        DB::statement(
            'alter table task_evidence drop constraint if exists fk_task_evidence_document'
        );
    }
};
