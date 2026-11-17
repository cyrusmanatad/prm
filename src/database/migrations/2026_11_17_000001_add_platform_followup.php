<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/platform-followup.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('The administration follow-up requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Skipped runs stay closed: the earlier schema has no 'skipped' state, and 'cancelled' is also never replayed.
        DB::unprepared("DROP FUNCTION app_skip_due_schedule(uuid,timestamptz),app_suspended_due_schedules(),app_skip_suspended_work(),app_partnership_paused(uuid);
            UPDATE schedule_runs SET state='cancelled' WHERE state='skipped';
            ALTER TABLE schedule_runs DROP CONSTRAINT schedule_runs_state_check;
            ALTER TABLE schedule_runs ADD CONSTRAINT schedule_runs_state_check CHECK(state IN ('ready','completed','cancelled'));");
        // Restore the directory and overview projections exactly as the console migration defined them.
        $previous = file_get_contents(database_path('sql/platform-console.sql'));
        foreach (['app_platform_accounts', 'app_platform_workspaces', 'app_platform_overview'] as $name) {
            $start = strpos($previous, 'CREATE FUNCTION '.$name.'(');
            $end = strpos($previous, 'END $$;', $start) + strlen('END $$;');
            DB::unprepared('CREATE OR REPLACE'.substr($previous, $start + strlen('CREATE'), $end - $start - strlen('CREATE')));
        }
    }
};
