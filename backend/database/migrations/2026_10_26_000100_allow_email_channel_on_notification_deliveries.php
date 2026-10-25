<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `email` to the delivery ledger's channel set (P3, R7).
 *
 * The retention notices are the first user-facing message the product must
 * deliver by email as well as in-app: "notices at 30/7/1 days, delivered by
 * email and in-app" (RETENTION-MODEL.md R7). The generic notification flow stays
 * in-app/push/LINE; only the legal notice path forces the email channel.
 *
 * A check constraint is widened rather than dropped: the ledger must still
 * reject a typo instead of inventing a fifth transport nobody sends.
 */
return new class extends Migration
{
    private const CHANNELS = "'in_app', 'push', 'line', 'email'";

    public function up(): void
    {
        DB::statement('alter table notification_deliveries drop constraint if exists chk_notification_deliveries_channel');
        DB::statement(
            'alter table notification_deliveries add constraint chk_notification_deliveries_channel '
            .'check (channel in ('.self::CHANNELS.'))'
        );
    }

    public function down(): void
    {
        DB::statement('alter table notification_deliveries drop constraint if exists chk_notification_deliveries_channel');
        DB::statement(
            'alter table notification_deliveries add constraint chk_notification_deliveries_channel '
            ."check (channel in ('in_app', 'push', 'line'))"
        );
    }
};
