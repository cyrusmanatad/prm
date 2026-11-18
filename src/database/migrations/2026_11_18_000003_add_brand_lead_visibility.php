<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/brand-lead-visibility.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Brand lead visibility requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Forward fixes only once anything is shared: rollback would discard registrations, their first
        // registration dates and Partner-authored shared profiles.
        if (DB::table('lead_registrations')->exists() || DB::table('partner_shared_profiles')->exists()) {
            throw new RuntimeException('Rollback refuses to discard lead registrations and shared Partner profiles. Apply a forward fix instead.');
        }
        DB::unprepared('DROP TRIGGER partnership_registrations_end ON partnerships;
            DROP TRIGGER lead_registration_source_delete ON pipeline_entries;
            DROP TRIGGER lead_registration_source_sync ON pipeline_entries;
            DROP FUNCTION app_partner_shared_profile(uuid),app_save_shared_profile(integer,text,text,text,text),app_partner_signed_in_at(uuid),
                app_record_workspace_sign_in(),app_end_partnership_registrations(),app_sync_lead_registrations(),app_withdraw_lead_registration(uuid,integer),
                app_update_lead_registration(uuid,integer,text,text,boolean),app_register_lead(uuid,uuid,uuid,text,text,boolean);
            DROP TABLE partner_shared_profiles,workspace_sign_ins,lead_registrations;
            DROP FUNCTION app_partnership_sharing(uuid);
            DROP INDEX partnership_brand_roster;');
    }
};
