<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform access, one page at a time: search, a view per grant state with counts, and a pinned operator.
 * app_platform_operators() is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/platform-operator-list.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('The administration console requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_platform_operator_list(jsonb,integer)');
        }
    }
};
