<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/partner-workspaces.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Portals require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/partner-workspaces-down.sql')));
        }
    }
};
