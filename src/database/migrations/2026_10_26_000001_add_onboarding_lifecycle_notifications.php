<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/onboarding-lifecycle.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Notifications require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/onboarding-lifecycle-down.sql')));
            // Restore the preceding dispatcher and policy definitions verbatim.
            $previous = file_get_contents(database_path('sql/onboarding-notifications.sql'));
            DB::unprepared(substr($previous, strpos($previous, 'DROP POLICY notification_read')));
        }
    }
};
