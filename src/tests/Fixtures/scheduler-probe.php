<?php

// Configure a synthetic task only in this CLI process; application routes stay empty.
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Tests\Fixtures\TenantProbeJob;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Scheduler probes require testing CLI.');
}
[$script, $marker, $mode, $actorId, $orgId] = $argv;
$schedule = app(Schedule::class);
$schedule->useCache('redis');
Event::listen(ScheduledTaskFailed::class, fn () => Redis::incr($marker.':failures'));
$event = $schedule->call(function () use ($marker, $mode, $actorId, $orgId): void {
    if (DB::selectOne('SELECT app_actor() AS actor, app_org() AS org')->actor !== null
        || DB::selectOne('SELECT app_org() AS org')->org !== null) {
        throw new LogicException('Scheduler inherited tenant context.');
    }
    Redis::incr($marker.':entered');
    if ($mode === 'fail') {
        throw new LogicException('Intentional scheduled probe failure');
    }
    if ($mode === 'hold') {
        Redis::blpop([$marker.':release'], 15);
    }
    Queue::connection('redis')->push(new TenantProbeJob((int) $actorId, $orgId), '', $marker);
    Redis::incr($marker.':dispatched');
})->name($marker)->everyMinute()->withoutOverlapping(1);
echo json_encode(['mutex' => $event->mutexName()], JSON_THROW_ON_ERROR)."\n";
flush();
exit($kernel->handle(new ArrayInput(['command' => 'schedule:run', '--no-interaction' => true]), new ConsoleOutput));
