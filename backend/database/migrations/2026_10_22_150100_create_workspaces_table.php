<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('slug');
            $table->jsonb('settings')->default('{}');
            $table->boolean('break_glass_enabled')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id', 'fk_workspaces_tenant')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();

            // Required as the target of composite (workspace_id, tenant_id) FKs.
            $table->unique(['id', 'tenant_id'], 'uq_workspaces_id_tenant');
            $table->index('tenant_id', 'ix_workspaces_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
