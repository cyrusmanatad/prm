<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/portals.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Portals require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION app_revoke_partner(uuid,uuid,boolean),app_accept_partner(text,uuid),app_invite_partner(uuid,text,text),app_create_typed_workspace(uuid,text,text); DROP TABLE partnerships,partner_invitations; DROP TRIGGER organization_kind_immutable ON organizations; DROP FUNCTION app_preserve_workspace_kind(); ALTER TABLE organizations DROP COLUMN kind;');
        }
    }
};
