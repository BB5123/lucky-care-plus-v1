<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->string('status')->default('active');
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_workspace_memberships_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'fk_workspace_memberships_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->unique(['workspace_id', 'user_id'], 'uq_workspace_memberships_workspace_user');
            $table->index(['tenant_id', 'workspace_id'], 'ix_workspace_memberships_tenant_workspace');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_memberships');
    }
};
