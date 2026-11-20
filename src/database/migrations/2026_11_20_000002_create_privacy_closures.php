<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/privacy-closures.sql')));
            DB::unprepared(file_get_contents(database_path('sql/privacy-closure-guards.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Privacy closures require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('privacy_closure_requests')->exists()) {
            throw new RuntimeException('Closure history and access barriers must be preserved. Apply a forward fix.');
        }
        DB::unprepared(file_get_contents(database_path('sql/privacy-closures-down.sql')));
    }
};
