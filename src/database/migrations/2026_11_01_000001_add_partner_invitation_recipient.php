<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/partner-invitation-recipient.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Portals require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION app_partner_invitation_recipient(text);');
        }
    }
};
