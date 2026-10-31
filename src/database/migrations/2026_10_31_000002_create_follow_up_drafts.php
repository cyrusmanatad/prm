<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/follow-up-drafts.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Follow-up drafts require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION app_ready_follow_up_drafts(); DROP TABLE follow_up_drafts;');
        }
    }
};
