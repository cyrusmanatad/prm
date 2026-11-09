<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/follow-up-approval-basis.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Follow-up approval invalidation requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX IF EXISTS follow_up_approved_basis;
                DROP FUNCTION app_invalidate_follow_up_approvals() CASCADE;
                DROP FUNCTION app_follow_up_content_changed() CASCADE;
                DROP FUNCTION app_reconcile_follow_up_approvals();
                DROP FUNCTION app_follow_up_basis(follow_up_drafts);');
        }
    }
};
