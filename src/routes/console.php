<?php

use App\Jobs\RecordWorkerHeartbeat;
use App\Services\BusinessSchedules;
use App\Services\FollowUpDrafts;
use App\Services\Monitoring;
use App\Services\NotificationOutbox;
use App\Services\ScheduledNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('notifications:dispatch', function (NotificationOutbox $outbox) {
    $this->info('Queued '.$outbox->dispatch().' pending notifications.');
})->purpose('Reconcile and dispatch committed business notification intent');

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping(2);

Artisan::command('automation:dispatch', function (BusinessSchedules $schedules) {
    $this->info('Queued '.$schedules->dispatch().' due schedules.');
})->purpose('Reconstruct due business schedules from PostgreSQL');

Schedule::command('automation:dispatch')->everyMinute()->withoutOverlapping(2);

Artisan::command('automation:notify', function (ScheduledNotifications $notices) {
    $this->info('Queued '.$notices->dispatch().' ready schedule notices.');
})->purpose('Reconstruct reminder and weekly review email intent from durable runs');

Schedule::command('automation:notify')->everyMinute()->withoutOverlapping(2);

Artisan::command('automation:drafts', function (FollowUpDrafts $drafts) {
    $this->info('Queued '.$drafts->dispatch().' ready follow-up drafts.');
})->purpose('Reconstruct private follow-up drafts from durable runs');

Schedule::command('automation:drafts')->everyMinute()->withoutOverlapping(2);

// Heartbeat for administration diagnostics: proves the scheduler ran, not that every task succeeded.
Schedule::call(fn () => DB::select("SELECT app_record_observation('scheduler')"))->everyMinute()->name('platform:heartbeat');

// M6-03 monitoring. The worker heartbeat proves the queue is being consumed; monitor:check evaluates every signal,
// alerts on changes and pings the dead-man URL. A stopped scheduler stops both, which only that outside ping can report.
Schedule::job(new RecordWorkerHeartbeat)->everyMinute()->name('monitor:worker-heartbeat');

Artisan::command('monitor:check', function (Monitoring $monitoring) {
    foreach ($monitoring->check() as $name => $result) {
        $this->line(sprintf('%-20s %-8s %s%s', $name, $result['breached'] ? 'breached' : 'ok', $result['value'] ?? 'unknown', $result['action'] === 'none' ? '' : '  -> '.$result['action']));
    }
})->purpose('Evaluate monitoring checks and announce alert changes');

// Scheduled in-process: the scheduler discards the output of scheduled commands, which would drop every alert log line.
Schedule::call(fn () => app(Monitoring::class)->check())->everyMinute()->name('monitor:check')->withoutOverlapping(2);
