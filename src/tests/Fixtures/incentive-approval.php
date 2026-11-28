<?php

// Isolated acceptance process for concurrent incentive approvals; credentials come from the test harness.
use App\Services\Incentives;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Acceptance probes require testing CLI.');
}
[$script, $actorId, $orgId, $award, $version] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR)."\n";
flush();
$result = 'ok';
try {
    app(TenantContext::class)->run((int) $actorId, $orgId, fn () => app(Incentives::class)->decide($award, (int) $version, true, null, null));
} catch (ValidationException $exception) {
    $result = array_key_first($exception->errors());
}
echo json_encode(['result' => $result], JSON_THROW_ON_ERROR)."\n";
