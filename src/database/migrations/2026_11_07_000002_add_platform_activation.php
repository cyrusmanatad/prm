<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/platform-activation.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_platform_denied(text); DROP FUNCTION IF EXISTS app_lock_platform_grant(); DROP FUNCTION app_platform_assign(uuid,integer,text,jsonb,timestamptz,timestamptz,text,uuid,uuid);
                DROP FUNCTION app_platform_directory(text,integer); DROP FUNCTION app_platform_audit(text,text,text); DROP FUNCTION app_platform_allowed(text);
                DROP TABLE platform_audit, platform_grants;
                ALTER TABLE users DROP COLUMN admin_totp_secret, DROP COLUMN admin_totp_confirmed_at, DROP COLUMN admin_totp_counter;');
        }
    }
};
