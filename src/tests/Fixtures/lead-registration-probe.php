<?php

// Concurrency probe for Brand lead visibility: one runtime-role request in its own process and transaction.
use App\Services\LeadRegistrations;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('testing')) {
    throw new RuntimeException('Testing CLI required.');
}
[$script, $action, $actor, $org, $partnership, $entry] = $argv + [null, '', '', '', '', ''];
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
try {
    $status = app(TenantContext::class)->run((int) $actor, $org, fn () => match ($action) {
        'register' => app(LeadRegistrations::class)->register($partnership, $entry, 'Probe lead', '', false)['status'],
        'revoke' => DB::selectOne('SELECT app_revoke_partner(?::uuid,?::uuid,false) AS allowed', [$partnership, (string) Str::uuid()])->allowed ? 'revoked' : 'refused',
    });
} catch (HttpException $e) {
    $status = (string) $e->getStatusCode();
} catch (ValidationException $e) {
    $status = 'unavailable';
}
echo json_encode(['status' => $status])."\n";
