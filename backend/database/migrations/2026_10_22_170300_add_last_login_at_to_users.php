<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.last_login_at` feeds the inactivity flow (ADR-016 §2).
 *
 * Workspace inactivity is derived from the most recent login of its active
 * members; there was no column that recorded a login before this migration.
 * Authentication paths write it (registration and password login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
