<?php

namespace App\Services;

use App\Jobs\QueueScheduleNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScheduledNotifications
{
    public function dispatch(): int
    {
        DB::select('SELECT app_reconcile_schedules()');
        $rows = DB::select('SELECT * FROM app_ready_schedule_notices()');
        foreach ($rows as $r) {
            QueueScheduleNotice::dispatch($r->actor_id, $r->org_id, $r->schedule_id, $r->id);
        }

        return count($rows);
    }

    public function consume(int $actor, string $org, string $schedule, string $run): void
    {
        app(BusinessSchedules::class)->execute($actor, $org, $schedule, $run, function (object $s, object $r) use ($actor, $org): void {
            // Follow-up drafts have a separate consumer in M5-05. Never complete them here.
            abort_unless(in_array($s->kind, ['reminder', 'weekly_digest'], true), 422);
            $id = (string) Str::uuid();
            DB::table('notification_outbox')->insert([
                'id' => $id, 'org_id' => $org, 'actor_id' => $actor, 'kind' => 'schedule.'.$s->kind, 'schedule_run_id' => $r->id,
            ]);
            app(Audit::class)->record($actor, $org, 'notification.requested', $id);
        });
    }
}
