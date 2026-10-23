<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/notification-outbox.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Notifications require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION app_lock_notification_invitation(uuid),app_pending_notifications(),app_reconcile_notifications(); DROP TABLE notification_outbox; ALTER TABLE partner_invitations DROP CONSTRAINT partner_invitation_org_id;');
        }
    }
};
