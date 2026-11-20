<?php

use App\Services\PrivacyExportData;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Disposable test probe only.');
}
[$script, $action, $actor, $org, $registration] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
try {
    $result = app(TenantContext::class)->run((int) $actor, $org, function () use ($action, $actor, $org, $registration) {
        if ($action === 'withdraw') {
            DB::select('SELECT app_withdraw_lead_registration(?::uuid,1)', [$registration]);

            return ['outcome' => 'withdrawn'];
        }
        $data = app(PrivacyExportData::class)->collect((int) $actor, $org);

        return ['outcome' => 'collected', 'shared_count' => count($data['lead_registrations'])];
    }, snapshot: $action === 'collect');
} catch (Throwable $exception) {
    $result = ['outcome' => 'denied', 'code' => (string) $exception->getCode()];
}
echo json_encode($result)."\n";
