<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Functions this migration replaced, with the file that holds each earlier definition. */
    private const REPLACED = [
        'app_create_typed_workspace' => 'portals.sql',
        'app_platform_grant_save' => 'platform-console.sql',
        'app_platform_notification_list' => 'platform-console.sql',
        'app_platform_notification' => 'platform-console.sql',
        'app_platform_operations' => 'platform-console.sql',
        'app_platform_resolve_notification' => 'delivery-controls.sql',
        'app_platform_overview' => 'platform-followup.sql',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/brand-approval.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Brand workspace approval requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Forward fixes only once anything is recorded: rollback would discard requests, decisions and the
        // approval provenance of every Brand workspace, and would reopen unreviewed Brand creation.
        if (DB::table('brand_requests')->exists()) {
            throw new RuntimeException('Rollback refuses to discard Brand requests and approved-workspace provenance. Apply a forward fix instead.');
        }
        DB::unprepared("DROP TRIGGER organization_brand_provenance ON organizations;
            DROP FUNCTION app_platform_workspace_approval(uuid),app_platform_brand_decide(uuid,integer,text,text,uuid,uuid),app_platform_brand_request(uuid),app_platform_brand_requests(jsonb,integer),
                app_brand_request_mine(uuid),app_brand_requests_mine(),app_brand_request_row(brand_requests),app_brand_request_revise(uuid,integer,text,text,text,text),
                app_brand_request_submit(uuid,text,text,text,text),app_brand_fields(text,text,text,text),app_account_notification_finish(uuid,text),
                app_account_notification_reserve(uuid,boolean,integer,integer),app_account_notification_defer(uuid,text),app_account_notifications_claim(),app_brand_provenance();
            DROP TABLE account_notification_resolutions,account_notification_outbox,brand_request_events,brand_requests;
            DROP FUNCTION app_brand_history_guard();
            DELETE FROM notification_delivery_windows WHERE scope LIKE 'account:%';
            UPDATE platform_grants SET permissions=(SELECT coalesce(array_agg(p),'{}') FROM unnest(permissions) p WHERE p<>'brands.review'),version=version+1 WHERE 'brands.review'=ANY(permissions);
            ALTER TABLE platform_grants DROP CONSTRAINT platform_grants_permissions_check;
            ALTER TABLE platform_grants ADD CONSTRAINT platform_grants_permissions_check CHECK(permissions <@ ARRAY['platform.owner','directory.read','plans.assign','notifications.read','notifications.reconcile','operations.read','audit.read','access.manage','support.request','cleanup.review']::text[]);");
        // Restore each replaced function exactly as its earlier migration defined it.
        foreach (self::REPLACED as $name => $file) {
            $previous = file_get_contents(database_path('sql/'.$file));
            preg_match_all('/CREATE (?:OR REPLACE )?FUNCTION '.$name.'\(.*?END \$\$;/s', $previous, $matches);
            $definition = end($matches[0]);
            if (! $definition) {
                throw new RuntimeException('Earlier definition of '.$name.' not found in '.$file.'.');
            }
            DB::unprepared(preg_replace('/^CREATE (?:OR REPLACE )?FUNCTION/', 'CREATE OR REPLACE FUNCTION', $definition));
        }
        DB::unprepared('DROP FUNCTION app_platform_capabilities();');
    }
};
