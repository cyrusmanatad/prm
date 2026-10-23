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
        DB::unprepared(file_get_contents(database_path('sql/manual-payments.sql')));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS invoice_allocated_guard ON invoice_drafts; DROP TABLE IF EXISTS payment_commands, payment_allocation_reversals, payment_allocations, manual_payments; DROP FUNCTION IF EXISTS app_finance_guard();');
        }
    }
};
