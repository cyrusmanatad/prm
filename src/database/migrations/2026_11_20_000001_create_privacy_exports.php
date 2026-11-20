<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/privacy-exports.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Privacy exports require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('privacy_exports')->exists()) {
            throw new RuntimeException('Privacy export history and artifact cleanup must be preserved. Apply a forward fix.');
        }
        DB::unprepared('DROP TABLE privacy_export_events,privacy_exports; DROP FUNCTION app_privacy_export_cleanup(uuid,boolean), app_privacy_export_expired(), app_privacy_account_events(), app_privacy_lock_sharing(), app_privacy_export_guard();');
    }
};
