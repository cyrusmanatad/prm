<?php

use App\Services\BusinessSchedules;
use App\Services\FollowUpDrafts;
use App\Services\Pipeline;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $action, $actor, $org, $lead, $draft, $schedule, $version] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
try {
    app(TenantContext::class)->run((int) $actor, $org, function () use ($action, $actor, $org, $lead, $draft, $schedule, $version): void {
        if ($action === 'handoff') {
            app(FollowUpDrafts::class)->transition((int) $actor, $org, $draft, (int) $version, 'handoff');
        } elseif ($action === 'lead') {
            app(Pipeline::class)->change((int) $actor, $org, $lead, 1, 'updated', ['brand_name' => 'Concurrent edit']);
        } else {
            app(BusinessSchedules::class)->revoke((int) $actor, $org, $schedule, 1);
        }
    });
    echo json_encode(['allowed' => true])."\n";
} catch (ValidationException $e) {
    echo json_encode(['allowed' => false, 'status' => $e->status])."\n";
} catch (HttpExceptionInterface $e) {
    echo json_encode(['allowed' => false, 'status' => $e->getStatusCode()])."\n";
}
