<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-005 / baseline v1.4.0 — explicit platform-administrator capability.
 *
 * The flag is deliberately on the user row rather than a workspace role:
 * workspace membership is tenant/workspace-scoped, while the knowledge corpus
 * is platform data with no workspace. ABD-019 records that platform
 * capabilities are never derived from a workspace role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
            $table->timestampTz('platform_admin_granted_at')->nullable();
            $table->foreignId('platform_admin_granted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->index('is_platform_admin', 'ix_users_is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('ix_users_is_platform_admin');
            $table->dropConstrainedForeignId('platform_admin_granted_by');
            $table->dropColumn(['is_platform_admin', 'platform_admin_granted_at']);
        });
    }
};
