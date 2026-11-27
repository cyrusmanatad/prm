<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/campaigns.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Campaigns require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if (DB::table('campaigns')->exists()) {
                throw new RuntimeException('Rollback would discard campaigns. Apply a forward fix.');
            }
            DB::unprepared('DROP FUNCTION app_campaign_partners(),app_save_campaign(uuid,integer,text,date,uuid); DROP TABLE campaigns');
        }
    }
};
