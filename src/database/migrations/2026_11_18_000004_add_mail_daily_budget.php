<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/mail-budget.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Mail budgets require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('mail_budget_attempts')->exists() || DB::table('mail_budget_policy')->where('enabled', true)->exists()) {
            throw new RuntimeException('Rollback refuses to discard active policy or mail budget consumption. Apply a forward fix.');
        }
        DB::unprepared('DROP FUNCTION app_account_notification_reserve_daily(uuid,boolean,integer,integer,boolean),
            app_mail_budget_snapshot(),app_mail_budget_consume(uuid,integer),app_mail_budget_reserve(text,integer);
            DROP TABLE mail_budget_attempts,mail_budget_policy;');
    }
};
