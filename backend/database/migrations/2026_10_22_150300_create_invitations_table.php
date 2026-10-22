<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->string('email')->nullable();
            $table->string('role');
            $table->string('token')->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'tenant_id'], 'fk_invitations_workspace_tenant')
                ->references(['id', 'tenant_id'])->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'fk_invitations_created_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'workspace_id'], 'ix_invitations_tenant_workspace');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
