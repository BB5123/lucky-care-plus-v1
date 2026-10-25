<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-archive encryption key (P4b-2a, RETENTION-ARCHIVE-DESIGN.md §3.1).
 *
 * The bundle is no longer encrypted directly with APP_KEY: a random 32-byte key
 * is generated per archive and wrapped with the application key. The wrapped
 * key is stored next to the manifest, so deleting it later makes the bundle
 * unreadable (crypto-shred) without touching the ciphertext.
 *
 * Legacy manifests (P4b-1) have NULL here; the service still reads those bundles
 * with the old "encrypted with APP_KEY" format, so no existing row breaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_archive_manifests', function (Blueprint $table) {
            $table->text('key_wrapped')->nullable()->after('archive_bytes');
            $table->string('key_algorithm', 64)->nullable()->after('key_wrapped');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_archive_manifests', function (Blueprint $table) {
            $table->dropColumn(['key_wrapped', 'key_algorithm']);
        });
    }
};
