<?php

use App\Services\InvoiceDrafts;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $actor, $org, $key] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
try {
    $result = app(TenantContext::class)->run((int) $actor, $org, fn () => app(InvoiceDrafts::class)->command((int) $actor, $org, $key, 'create', [
        'recipient_name' => 'Concurrent', 'currency' => 'USD', 'due_date' => null,
        'lines' => [['description' => 'Work', 'quantity' => 1, 'unit_price_minor' => '100']],
    ], null));
    echo json_encode(['id' => $result])."\n";
} catch (QueryException $e) {
    echo json_encode(['status' => 422, 'sqlstate' => $e->errorInfo[0]])."\n";
}
