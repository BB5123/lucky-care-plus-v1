<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('from_person_id');
            $table->uuid('to_person_id');
            $table->string('kinship');
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_person_relationships_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign(['from_person_id', 'tenant_id', 'workspace_id'], 'fk_person_relationships_from_person')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons')
                ->cascadeOnDelete();
            $table->foreign(['to_person_id', 'tenant_id', 'workspace_id'], 'fk_person_relationships_to_person')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_person_relationships_tenant_workspace');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_relationships');
    }
};
