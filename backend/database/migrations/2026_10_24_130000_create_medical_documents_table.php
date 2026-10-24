<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * medical_documents — private metadata for an uploaded health document.
 *
 * The object itself never carries a user filename or a health-data fragment in
 * its key (N-02): the key is exactly `documents/{tenant_uuid}/{document_uuid}`.
 * `storage_path` and `original_name` are encrypted at rest by the model casts,
 * the bucket is private, and every read is re-authorized through the
 * Authorizer pipeline.
 *
 * `scan_status` defaults to `skipped` because this deployment has no scanner
 * configured. When `documents.scan_required` is true the upload path fails
 * closed (503) instead of accepting an unscanned object.
 *
 * The SELECT-only `document_download_lookup` policy lets the signed-download
 * resolver find one row before the tenant/workspace context is known. It is
 * intentionally SELECT-only: INSERT/UPDATE/DELETE still require a real
 * tenant/workspace RLS context, and knowing an id is never sufficient for
 * access because the Authorizer re-checks the profile afterwards (ABD-014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('profile_id');
            $table->unsignedBigInteger('uploaded_by');
            $table->string('kind', 32);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('storage_disk', 32);
            // Ciphertext at rest: the path is not useful without the app key.
            $table->text('storage_path');
            // A filename can contain a person's name, so it is encrypted too.
            $table->text('original_name')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->string('scan_status', 16)->default('skipped');
            $table->string('status', 16)->default('available');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_medical_documents_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['profile_id', 'tenant_id', 'workspace_id'], 'fk_medical_documents_profile')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('health_profiles')
                ->cascadeOnDelete();
            $table->foreign('uploaded_by', 'fk_medical_documents_uploaded_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            // Target for `task_evidence.document_id` and any future document
            // reference that must stay inside one tenant/workspace.
            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_medical_documents_id_tenant_workspace');
            $table->unique(['storage_disk', 'storage_path'], 'uq_medical_documents_storage_location');
            $table->index(['tenant_id', 'workspace_id'], 'ix_medical_documents_tenant_workspace');
            $table->index(['profile_id', 'created_at'], 'ix_medical_documents_profile_created');
            $table->index(['workspace_id', 'status'], 'ix_medical_documents_workspace_status');
        });

        DB::statement(
            'alter table medical_documents add constraint chk_medical_documents_kind '
            ."check (kind in ('lab_result', 'prescription', 'discharge_summary', 'photo', 'other'))"
        );
        DB::statement(
            'alter table medical_documents add constraint chk_medical_documents_scan_status '
            ."check (scan_status in ('skipped', 'clean', 'infected'))"
        );
        DB::statement(
            'alter table medical_documents add constraint chk_medical_documents_status '
            ."check (status in ('pending', 'available', 'quarantined', 'deleted'))"
        );
        DB::statement(
            'alter table medical_documents add constraint chk_medical_documents_size '
            .'check (size_bytes > 0)'
        );
        DB::statement(
            'alter table medical_documents add constraint chk_medical_documents_version '
            .'check (version >= 1)'
        );

        DB::statement('alter table medical_documents enable row level security');
        DB::statement('alter table medical_documents force row level security');
        DB::statement(<<<'SQL'
            create policy tenant_isolation on medical_documents
                using (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
                with check (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                    and workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid
                )
        SQL);
        DB::statement(<<<'SQL'
            create policy document_download_lookup on medical_documents
                for select
                using (
                    id = nullif(current_setting('app.document_lookup_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop policy if exists document_download_lookup on medical_documents');
        DB::statement('drop policy if exists tenant_isolation on medical_documents');
        DB::statement('alter table medical_documents no force row level security');
        DB::statement('alter table medical_documents disable row level security');

        Schema::dropIfExists('medical_documents');
    }
};
