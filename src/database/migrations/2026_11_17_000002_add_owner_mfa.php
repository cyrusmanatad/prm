<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/owner-mfa.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Workspace-owner MFA requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION app_platform_reset_authenticator(bigint,text),app_account_use_recovery_code(text),app_account_set_recovery_codes(text[]),
                app_account_security_event(text),app_account_mfa_state(),app_account_requires_mfa(bigint),app_recovery_digest(text);
                DROP TABLE account_security_events,account_recovery_codes;');
        }
    }
};
