<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('person_id');
            $table->string('type');
            $table->text('value');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_contact_points_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['person_id', 'tenant_id', 'workspace_id'], 'fk_contact_points_person')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_contact_points_tenant_workspace');
        });

        DB::statement(
            'alter table contact_points add constraint chk_contact_points_type '
            ."check (type in ('phone', 'email', 'line', 'other'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_points');
    }
};
