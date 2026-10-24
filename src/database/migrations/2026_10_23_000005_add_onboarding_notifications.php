<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/onboarding-notifications.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Notifications require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/onboarding-notifications-down.sql')));
        }
    }
};
