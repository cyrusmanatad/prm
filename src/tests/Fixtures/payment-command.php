<?php

use App\Services\ManualPayments;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $actor, $org, $key, $payment, $invoice] = $argv;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
try {
    $id = app(TenantContext::class)->run((int) $actor, $org, fn () => app(ManualPayments::class)->command((int) $actor, $org, $key, 'allocate', [
        'version' => 1, 'invoice_id' => $invoice, 'invoice_version' => 1, 'amount_minor' => '600',
    ], $payment));
    echo json_encode(['id' => $id])."\n";
} catch (ValidationException $e) {
    echo json_encode(['status' => $e->status])."\n";
}
