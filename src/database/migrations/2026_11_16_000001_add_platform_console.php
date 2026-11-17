<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/platform-console.sql')));
            DB::unprepared(file_get_contents(database_path('sql/platform-governance.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('The administration console requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('platform_suspensions')->whereNull('lifted_at')->exists() || DB::table('support_grants')->where('state', 'approved')->exists()) {
            throw new RuntimeException('Rollback refuses to remove active suspensions or support grants: it would silently change who has access. Lift or end them first.');
        }
        // Audit rows stay in platform_audit; projections, suspension history, support grants and dry runs are removed.
        DB::unprepared(file_get_contents(database_path('sql/platform-governance-down.sql')));
        DB::unprepared("DROP FUNCTION app_platform_overview(),app_platform_grant_save(bigint,integer,text[],boolean,text),app_platform_operators(),app_platform_audit_event(text,text),
            app_platform_audit_list(jsonb,integer),app_platform_operations(),app_record_observation(text),app_job_name(text),app_platform_notification(uuid),
            app_platform_notification_list(jsonb,integer),app_platform_plan(uuid),app_platform_assignments(jsonb,integer),app_platform_plans(text,integer),app_platform_workspace(uuid),
            app_platform_workspaces(jsonb,integer),app_platform_account(bigint),app_platform_activity(text,uuid),app_platform_accounts(jsonb,integer),app_like(text),
            app_entitlement_state(uuid),app_platform_any(),app_platform_require(text),app_account_suspended(bigint),app_workspace_suspended(uuid);
            DROP TABLE platform_observations,platform_suspensions;
            UPDATE platform_grants SET permissions=(SELECT coalesce(array_agg(p),'{}') FROM unnest(permissions) p WHERE p NOT IN ('operations.read','audit.read','access.manage','support.request','cleanup.review')),version=version+1
              WHERE permissions && ARRAY['operations.read','audit.read','access.manage','support.request','cleanup.review'];
            ALTER TABLE platform_grants DROP CONSTRAINT platform_grants_permissions_check;
            ALTER TABLE platform_grants ADD CONSTRAINT platform_grants_permissions_check CHECK(permissions <@ ARRAY['platform.owner','directory.read','plans.assign','notifications.read','notifications.reconcile']::text[]);");
    }
};
