<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Source campaign on lead registrations (D43). The registration functions gain a campaign argument, so the
 * earlier signatures are dropped and restored on rollback.
 */
return new class extends Migration
{
    /** Functions this migration replaced, with the file that holds each earlier definition. */
    private const REPLACED = [
        'app_register_lead' => 'brand-lead-visibility.sql',
        'app_update_lead_registration' => 'brand-lead-visibility.sql',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/campaign-lead-links.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Campaign lead links require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('lead_registrations')->whereNotNull('campaign_id')->exists()) {
            throw new RuntimeException('Rollback would discard source campaign links. Apply a forward fix.');
        }
        DB::unprepared('DROP FUNCTION app_register_lead(uuid,uuid,uuid,text,text,boolean,uuid), app_update_lead_registration(uuid,integer,text,text,boolean,uuid), app_campaign_on_partnership(uuid,uuid)');
        // Restore each replaced function exactly as its earlier migration defined it.
        foreach (self::REPLACED as $name => $file) {
            preg_match_all('/CREATE (?:OR REPLACE )?FUNCTION '.$name.'\(.*?END \$\$;/s', file_get_contents(database_path('sql/'.$file)), $matches);
            $definition = end($matches[0]);
            if (! $definition) {
                throw new RuntimeException('Earlier definition of '.$name.' not found in '.$file.'.');
            }
            DB::unprepared($definition);
        }
        DB::unprepared('REVOKE ALL ON FUNCTION app_register_lead(uuid,uuid,uuid,text,text,boolean),app_update_lead_registration(uuid,integer,text,text,boolean) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION app_register_lead(uuid,uuid,uuid,text,text,boolean),app_update_lead_registration(uuid,integer,text,text,boolean) TO weavdash_app;
            ALTER TABLE lead_registrations DROP CONSTRAINT lead_registration_campaign_time, DROP CONSTRAINT lead_registration_campaign,
                DROP COLUMN campaign_confirmed_at, DROP COLUMN campaign_linked_at, DROP COLUMN campaign_id;
            DROP INDEX campaigns_partnership_key');
    }
};
