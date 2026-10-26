<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/automation-schedules.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Automation requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION app_due_schedules(),app_reconcile_schedules(),app_schedule_current(uuid),app_schedule_basis(business_schedules);
                DROP TABLE automation_consent_events,schedule_runs,business_schedules,automation_preferences;');
        }
    }
};
