<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/follow-up-delivery.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Follow-up delivery requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('notification_outbox')->where('kind', 'like', 'follow_up.%')->exists()) {
            throw new RuntimeException('Rollback refuses to remove follow-up delivery history. Restore a reviewed backup instead.');
        }
        DB::unprepared('DROP FUNCTION app_cancel_follow_up_notice() CASCADE;
            DROP FUNCTION app_reconcile_notifications(),app_follow_up_notice_current(uuid),app_follow_up_notice_basis(notification_outbox);
            DROP FUNCTION app_reconcile_notifications_before_delivery();
            ALTER TABLE notification_outbox DROP CONSTRAINT notification_subject_kind,DROP CONSTRAINT notification_follow_up_once,DROP CONSTRAINT notification_follow_up,
                DROP COLUMN follow_up_draft_id,DROP COLUMN follow_up_version,DROP COLUMN follow_up_preference_version;
            ALTER TABLE follow_up_drafts DROP CONSTRAINT follow_up_owner;
            ALTER TABLE automation_preferences DROP COLUMN follow_up_reviews;');
        $previous = file_get_contents(database_path('sql/scheduled-notifications.sql'));
        $start = strpos($previous, 'ADD CONSTRAINT notification_subject_kind CHECK');
        $end = strpos($previous, 'CREATE FUNCTION app_ready_schedule_notices()');
        DB::unprepared('ALTER TABLE notification_outbox '.substr($previous, $start, $end - $start));
        DB::unprepared(substr($previous, strpos($previous, 'CREATE OR REPLACE FUNCTION app_reconcile_notifications()')));
        DB::unprepared('REVOKE ALL ON FUNCTION app_reconcile_notifications() FROM PUBLIC; GRANT EXECUTE ON FUNCTION app_reconcile_notifications() TO weavdash_app;');
    }
};
