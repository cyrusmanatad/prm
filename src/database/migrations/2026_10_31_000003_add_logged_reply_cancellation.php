<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/logged-reply-cancellation.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Logged-reply cancellation requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER logged_reply_cancellation ON pipeline_activities;
DROP FUNCTION app_cancel_logged_reply();
-- Retain reply history and terminal drafts when reverting the feature.
UPDATE follow_up_drafts SET state='discarded' WHERE state='cancelled';
ALTER TABLE follow_up_drafts DROP CONSTRAINT follow_up_drafts_state_check;
ALTER TABLE follow_up_drafts ADD CONSTRAINT follow_up_drafts_state_check CHECK(state IN ('draft','approved','discarded'));
SQL);
        }
    }
};
