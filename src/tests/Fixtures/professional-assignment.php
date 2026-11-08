<?php

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Test-only probe');
}
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS id')->id])."\n";
flush();
try {
    app(TenantContext::class)->run((int) $argv[1], null, function () use ($argv) {
        DB::select("SELECT set_config('app.admin_version','1',true)");
        DB::select('SELECT app_platform_assign(?::uuid,0,?,?::jsonb,?::timestamptz,?::timestamptz,?,?::uuid,?::uuid)', [$argv[2], 'Concurrent synthetic '.Str::uuid(), json_encode(array_fill_keys(['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'], 10)), now()->subHour(), now()->addDay(), 'Concurrent activation', (string) Str::uuid(), (string) Str::uuid()]);
    });
    echo json_encode(['state' => 'committed'])."\n";
} catch (PDOException $error) {
    echo json_encode(['state' => $error->errorInfo[0]])."\n";
}
