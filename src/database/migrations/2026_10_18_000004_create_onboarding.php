<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Onboarding operations require PostgreSQL.');
            }

            return;
        }
        DB::unprepared(file_get_contents(database_path('sql/onboarding.sql')));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_capture_lead(text,uuid,text,text,text,text,uuid); DROP FUNCTION IF EXISTS app_public_kit(uuid); DROP FUNCTION IF EXISTS app_exchange_onboarding(text,text,uuid); DROP FUNCTION IF EXISTS app_onboarding_scope(text); DROP TABLE IF EXISTS onboarding_events,onboarding_submissions,onboarding_documents; DROP TABLE IF EXISTS onboarding_sessions; DROP TABLE IF EXISTS onboarding_requests; DROP FUNCTION IF EXISTS app_onboarding_access(uuid); DROP TABLE IF EXISTS public_lead_receipts,lead_links,media_kits;');
        }
    }
};
