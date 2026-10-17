<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite supports identity-only tests, never workspace or isolation tests.
        if (DB::getDriverName() !== 'pgsql') {
            if (! app()->environment('testing')) {
                throw new RuntimeException('WeavDash requires PostgreSQL for tenant isolation.');
            }

            return;
        }

        DB::unprepared(file_get_contents(database_path('sql/foundation.sql')));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP FUNCTION IF EXISTS app_create_workspace(uuid, text);
            DROP TABLE IF EXISTS audit_logs;
            DROP POLICY IF EXISTS organization_discovery ON organizations;
            DROP POLICY IF EXISTS organization_settings ON organizations;
            DROP FUNCTION IF EXISTS app_has_membership(uuid, text[]);
            DROP TABLE IF EXISTS memberships;
            DROP TABLE IF EXISTS organizations;
            DROP FUNCTION IF EXISTS app_org(); DROP FUNCTION IF EXISTS app_actor();');
    }
};
