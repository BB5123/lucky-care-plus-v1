<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authentication columns for the global identity table.
 *
 * `users` deliberately has no tenant/workspace columns and no RLS policy:
 * an account exists before any workspace and can belong to several.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->unique('users_phone_unique');
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('pin_hash')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestampTz('pin_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_phone_unique');
            $table->dropColumn([
                'phone',
                'phone_verified_at',
                'pin_hash',
                'pin_failed_attempts',
                'pin_locked_until',
            ]);
        });
    }
};
