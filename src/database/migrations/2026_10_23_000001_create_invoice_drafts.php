<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Financial records require PostgreSQL.');
            }

            return;
        }
        if (PHP_INT_SIZE !== 8) {
            throw new RuntimeException('Financial records require 64-bit PHP.');
        }
        DB::unprepared(file_get_contents(database_path('sql/invoice-drafts.sql')));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TABLE IF EXISTS financial_commands; DROP TABLE IF EXISTS invoice_draft_lines; DROP TABLE IF EXISTS invoice_drafts;');
        }
    }
};
