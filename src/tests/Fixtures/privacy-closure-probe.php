<?php

use App\Models\User;
use App\Services\AccountNotifications;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Disposable closure probe only.');
}
[$script, $action, $actor, $org, $hash, $reference] = $argv;
$workspace = $org === '-' ? null : $org;
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
try {
    if ($action === 'mail') {
        User::findOrFail((int) $actor)->sendPasswordResetNotification('synthetic-closure-probe');
        $result = ['outcome' => 'mail', 'captured' => count(Mail::mailer('array')->getSymfonyTransport()->messages())];
    } elseif ($action === 'account-notice') {
        app(AccountNotifications::class)->deliver($reference);
        $result = ['outcome' => 'finished'];
    } elseif ($action === 'close') {
        app(TenantContext::class)->run((int) $actor, null, fn () => DB::select('SELECT app_privacy_close(?::uuid,?,?::uuid,?,?)', [$reference, $workspace ? 'workspace' : 'account', $workspace, $hash, 'Synthetic concurrent closure']), accessBarrier: false);
        $result = ['outcome' => 'closed'];
    } else {
        $count = app(TenantContext::class)->run((int) $actor, $workspace, fn () => DB::table('pipeline_entries')->where('org_id', $workspace)->count(), snapshot: true);
        $result = ['outcome' => 'read', 'count' => $count];
    }
} catch (Throwable $error) {
    $result = ['outcome' => 'denied', 'code' => (string) $error->getCode()];
}
echo json_encode($result)."\n";
