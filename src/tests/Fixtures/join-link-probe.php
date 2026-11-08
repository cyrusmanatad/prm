<?php

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('testing')) {
    throw new RuntimeException('Testing CLI required.');
}
[$script, $actor, $org, $code, $action, $link] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
$allowed = app(TenantContext::class)->run((int) $actor, $org, fn () => $action === 'disable'
    ? DB::selectOne('SELECT app_disable_join_link(?::uuid,?::uuid) AS allowed', [(string) Str::uuid(), $link])->allowed
    : DB::selectOne('SELECT app_join_partner(?,?::uuid) AS allowed', [hash('sha256', $code), (string) Str::uuid()])->allowed);
echo json_encode(['allowed' => $allowed])."\n";
