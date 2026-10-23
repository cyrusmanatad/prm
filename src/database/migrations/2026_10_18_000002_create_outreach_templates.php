<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Outreach template operations require PostgreSQL.');
            }

            return;
        }
        DB::unprepared(file_get_contents(database_path('sql/outreach-templates.sql')));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TABLE IF EXISTS outreach_templates;');
        }
    }
};
