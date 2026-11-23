<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PRIV-05: report-only reconciliation of managed onboarding uploads (P4, D40). It records findings and never deletes
 * a stored file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/upload-reconciliation.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Upload reconciliation requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Findings are derived and regenerate on the next run; dropping them loses only observation history.
        DB::unprepared('DROP FUNCTION IF EXISTS app_upload_reconciliation(text,integer),app_upload_reconcile_record(uuid,text,jsonb,integer,integer,integer,text),app_upload_reconcile_documents();
            DROP TABLE IF EXISTS upload_reconciliation_findings; DROP TABLE IF EXISTS upload_reconciliation_runs;');
    }
};
