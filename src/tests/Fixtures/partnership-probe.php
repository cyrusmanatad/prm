<?php

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('testing')) {
    throw new RuntimeException('Testing CLI required.');
}
[$script, $actor, $org, $digest] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
try {
    $allowed = app(TenantContext::class)->run((int) $actor, $org, fn () => DB::selectOne('SELECT app_accept_partner(?::text,?::uuid) AS allowed', [$digest, (string) Str::uuid()])->allowed);
    $status = $allowed ? 200 : 404;
} catch (HttpException $e) {
    $status = $e->getStatusCode();
}
echo json_encode(['status' => $status, 'actor' => DB::selectOne('SELECT app_actor() AS actor')->actor])."\n";
