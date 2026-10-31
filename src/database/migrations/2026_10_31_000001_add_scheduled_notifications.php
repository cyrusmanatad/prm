<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/scheduled-notifications.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Scheduled notifications require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/scheduled-notifications-down.sql')));
            $previous = file_get_contents(database_path('sql/onboarding-lifecycle.sql'));
            DB::unprepared(substr($previous, strpos($previous, 'DROP POLICY notification_read'), strpos($previous, '-- No caller-selected') - strpos($previous, 'DROP POLICY notification_read')));
            DB::unprepared(substr($previous, strpos($previous, 'CREATE OR REPLACE FUNCTION app_reconcile_notifications()'), strpos($previous, 'REVOKE ALL ON FUNCTION app_queue') - strpos($previous, 'CREATE OR REPLACE FUNCTION app_reconcile_notifications()')));
        }
    }
};
