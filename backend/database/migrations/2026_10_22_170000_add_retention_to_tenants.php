<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenants.retention_override` (ADR-016 §4).
 *
 * The column already ships with `create_tenants_table`, so this migration is a
 * forward-compatibility guard for databases created before the column was
 * announced. It only adds the column when it is genuinely missing; the earlier
 * migration remains its owner, therefore `down()` never drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenants', 'retention_override')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->jsonb('retention_override')->nullable();
        });
    }

    public function down(): void
    {
        // Owned by create_tenants_table when the column pre-existed; this
        // migration must not remove data it did not create.
    }
};
