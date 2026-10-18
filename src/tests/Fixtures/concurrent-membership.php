<?php

// Isolated acceptance process; credentials supplied only by the test harness.
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Acceptance probes require testing CLI.');
}
[$script, $action, $actorId, $orgId] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR)."\n";
flush();
$status = 200;
try {
    if (in_array($action, ['revoke', 'demote'], true)) {
        $changed = DB::table('memberships')->where('org_id', $orgId)->where('user_id', (int) $actorId)
            ->update($action === 'revoke' ? ['revoked_at' => now()] : ['role' => 'viewer']);
        if ($changed !== 1) {
            throw new RuntimeException('Expected exactly one synthetic membership.');
        }
    } else {
        app(TenantContext::class)->run((int) $actorId, $orgId,
            fn () => app(Workspaces::class)->rename((int) $actorId, $orgId, 'Concurrent write'));
    }
} catch (HttpException $exception) {
    $status = $exception->getStatusCode();
}
echo json_encode([
    'status' => $status, 'actor' => DB::selectOne('SELECT app_actor() AS value')->value,
    'org' => DB::selectOne('SELECT app_org() AS value')->value,
    'transactions' => DB::connection()->transactionLevel(),
], JSON_THROW_ON_ERROR)."\n";
