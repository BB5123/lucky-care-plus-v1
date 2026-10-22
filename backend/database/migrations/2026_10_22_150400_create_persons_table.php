<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('display_name');
            $table->date('birth_date')->nullable();
            $table->uuid('managed_by_person_id')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_persons_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'fk_persons_user')
                ->references('id')->on('users')
                ->nullOnDelete();

            // Lets dependent persons point at their manager without crossing
            // tenant/workspace boundaries.
            $table->unique(['id', 'tenant_id', 'workspace_id'], 'uq_persons_id_tenant_workspace');
            $table->foreign(['managed_by_person_id', 'tenant_id', 'workspace_id'], 'fk_persons_managed_by')
                ->references(['id', 'tenant_id', 'workspace_id'])->on('persons');

            $table->index(['tenant_id', 'workspace_id'], 'ix_persons_tenant_workspace');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
