<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the encrypted bundle location to the archive inventory (P4b).
 *
 * The manifest row is written first (counts + hash), then the bundle is written
 * and these columns point at it. A manifest without a path is a valid P4a row
 * from before the bundle existed; the drill reports it instead of pretending the
 * archive is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_archive_manifests', function (Blueprint $table) {
            $table->string('archive_path', 512)->nullable()->after('sha256');
            $table->string('archive_sha256', 64)->nullable()->after('archive_path');
            $table->unsignedBigInteger('archive_bytes')->nullable()->after('archive_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_archive_manifests', function (Blueprint $table) {
            $table->dropColumn(['archive_path', 'archive_sha256', 'archive_bytes']);
        });
    }
};
