<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes for SMS login, phone verification, and PIN reset.
 *
 * No RLS: OTP codes belong to the global identity (`users`), which is not
 * tenant-scoped. The table never stores a plaintext code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel')->default('sms');
            $table->string('destination');
            $table->string('purpose');
            $table->string('code_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampsTz();

            $table->index(['user_id', 'purpose', 'consumed_at'], 'ix_otp_codes_user_purpose_consumed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
