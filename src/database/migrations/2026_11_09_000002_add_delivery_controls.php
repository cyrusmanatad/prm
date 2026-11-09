<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/delivery-controls.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Delivery controls require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('notification_resolutions')->exists()) {
            throw new RuntimeException('Rollback refuses to remove recorded operator resolutions. Restore a reviewed backup instead.');
        }
        DB::unprepared("DROP FUNCTION app_claim_notifications(),app_notification_budget(integer,integer),app_platform_notifications(uuid,text,integer),app_platform_resolve_notification(uuid,integer,text,text);
            DROP TABLE notification_resolutions,notification_delivery_windows;
            ALTER TABLE notification_outbox DROP COLUMN available_at,DROP COLUMN dispatch_after,DROP COLUMN defer_count,DROP COLUMN failure_code;
            UPDATE platform_grants SET permissions=array_remove(array_remove(permissions,'notifications.read'),'notifications.reconcile'),version=version+1 WHERE permissions && ARRAY['notifications.read','notifications.reconcile'];
            ALTER TABLE platform_grants DROP CONSTRAINT platform_grants_permissions_check;
            ALTER TABLE platform_grants ADD CONSTRAINT platform_grants_permissions_check CHECK(permissions <@ ARRAY['platform.owner','directory.read','plans.assign']::text[]);");
        $previous = file_get_contents(database_path('sql/notification-outbox.sql'));
        $start = strpos($previous, 'CREATE FUNCTION app_pending_notifications()');
        $end = strpos($previous, 'CREATE FUNCTION app_reconcile_notifications()');
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', substr($previous, $start, $end - $start)));
    }
};
