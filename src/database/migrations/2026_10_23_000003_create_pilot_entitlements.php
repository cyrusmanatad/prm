<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Financial records require PostgreSQL.');
            }

            return;
        }
        if (PHP_INT_SIZE !== 8) {
            throw new RuntimeException('Financial records require 64-bit PHP.');
        }
        DB::unprepared(file_get_contents(database_path('sql/pilot-entitlements.sql')));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $source = file_get_contents(database_path('sql/onboarding.sql'));
            preg_match('/CREATE FUNCTION app_capture_lead\(.*?END \$\$;/s', $source, $match);
            DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $match[0]));
            DB::unprepared('DROP TRIGGER IF EXISTS pipeline_entries_pilot_quota ON pipeline_entries; DROP TRIGGER IF EXISTS brand_contacts_pilot_quota ON brand_contacts; DROP TRIGGER IF EXISTS reminders_pilot_quota ON reminders; DROP TRIGGER IF EXISTS onboarding_requests_pilot_quota ON onboarding_requests; DROP TRIGGER IF EXISTS outreach_templates_pilot_quota ON outreach_templates; DROP TRIGGER IF EXISTS invoice_drafts_pilot_quota ON invoice_drafts; DROP TRIGGER IF EXISTS manual_payments_pilot_quota ON manual_payments; DROP FUNCTION IF EXISTS app_enforce_pilot_quota(); DROP TABLE IF EXISTS pilot_admin_events, pilot_assignments, pilot_plan_versions;');
        }
    }
};
