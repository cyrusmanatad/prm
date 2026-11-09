<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE onboarding_documents DROP CONSTRAINT onboarding_documents_scan_state_check');
            DB::statement("ALTER TABLE onboarding_documents ADD CONSTRAINT onboarding_documents_scan_state_check CHECK (scan_state IN ('pending','clean','rejected','unscanned'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if (DB::table('onboarding_documents')->where('scan_state', 'unscanned')->exists()) {
                throw new RuntimeException('Quarantine or scan existing staging files before rolling back this migration.');
            }
            DB::statement('ALTER TABLE onboarding_documents DROP CONSTRAINT onboarding_documents_scan_state_check');
            DB::statement("ALTER TABLE onboarding_documents ADD CONSTRAINT onboarding_documents_scan_state_check CHECK (scan_state IN ('pending','clean','rejected'))");
        }
    }
};
