<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Brand incentives and awards (D44), plus their place in the workspace closure review. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/incentives.sql')));
            DB::unprepared(file_get_contents(database_path('sql/incentive-privacy-inventory.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Incentives require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('incentives')->exists()) {
            throw new RuntimeException('Rollback would discard incentives. Apply a forward fix.');
        }
        // Restore the closure inventory exactly as the campaign migration defined it.
        preg_match('/CREATE (?:OR REPLACE )?FUNCTION app_privacy_inventory\(.*?END \$\$;/s', file_get_contents(database_path('sql/campaign-privacy-inventory.sql')), $match);
        if (! $match) {
            throw new RuntimeException('Earlier definition of app_privacy_inventory not found.');
        }
        DB::unprepared(preg_replace('/^CREATE (?:OR REPLACE )?FUNCTION/', 'CREATE OR REPLACE FUNCTION', $match[0]));
        DB::unprepared('DROP TRIGGER lead_registration_award_campaign ON lead_registrations;
            DROP TRIGGER lead_registration_incentive_claims ON lead_registrations;
            DROP FUNCTION app_guard_award_campaign(), app_cancel_incentive_claims(), app_reverse_incentive_award(uuid,integer,text),
                app_fulfil_incentive_award(uuid,integer,date,text), app_decide_incentive_award(uuid,integer,boolean,bigint,text),
                app_lock_incentive_award(uuid), app_claim_incentive_award(uuid,uuid,uuid,uuid[],text), app_end_incentive(uuid,integer),
                app_save_incentive(uuid,integer,jsonb,boolean), app_incentive_has_room(uuid), app_incentive_counts(uuid,uuid), app_stage_rank(text);
            DROP TABLE incentive_award_registrations, incentive_awards, incentive_partners, incentives');
    }
};
