<?php

use App\Services\Audit;
use App\Services\BusinessSchedules;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $action, $actor, $org, $schedule, $run] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
if ($action === 'execute') {
    app(BusinessSchedules::class)->execute((int) $actor, $org, $schedule, $run, function () use ($actor, $org, $schedule): void {
        app(Audit::class)->record((int) $actor, $org, 'schedule.concurrent.intent', $schedule);
    });
} else {
    app(TenantContext::class)->run((int) $actor, $org, fn () => app(BusinessSchedules::class)->revoke((int) $actor, $org, $schedule, 1));
}
echo json_encode(['state' => app(TenantContext::class)->run((int) $actor, $org, fn () => DB::table('schedule_runs')->where('id', $run)->value('state'))])."\n";
