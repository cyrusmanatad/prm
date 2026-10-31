<?php

use App\Services\FollowUpDrafts;
use App\Services\Pipeline;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $action, $actor, $org, $lead, $schedule, $run] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
if ($action === 'handoff') {
    try {
        app(TenantContext::class)->run((int) $actor, $org, fn () => app(FollowUpDrafts::class)->transition((int) $actor, $org, $schedule, 2, 'handoff'));
        echo json_encode(['allowed' => true])."\n";
    } catch (ValidationException $e) {
        echo json_encode(['allowed' => false, 'status' => $e->status])."\n";
    }
    exit;
} elseif ($action === 'prepare') {
    app(FollowUpDrafts::class)->consume((int) $actor, $org, $schedule, $run);
} else {
    app(TenantContext::class)->run((int) $actor, $org, fn () => app(Pipeline::class)->addActivity((int) $actor, $org, $lead, 1, 'reply', 'Concurrent reply'));
}
echo json_encode(['done' => true])."\n";
