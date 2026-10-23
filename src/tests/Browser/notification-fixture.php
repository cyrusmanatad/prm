<?php

// Runtime-role, local-only queue/mail capture for synthetic browser identities.
use App\Jobs\DeliverNotification;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || config('mail.default') !== 'array'
    || ! preg_match('/^m1-browser-notifications-[a-z0-9-]+@example\.test$/', $email)) {
    throw new RuntimeException('Only synthetic local notification capture is allowed.');
}
$user = User::where('email', $email)->firstOrFail();
$org = app(TenantContext::class)->run($user->id, null, fn () => DB::table('memberships')->where('user_id', $user->id)->where('role', 'owner')->value('org_id'));
$rows = app(TenantContext::class)->run($user->id, $org, fn () => DB::table('notification_outbox')->where('state', 'pending')->get());
foreach ($rows as $row) {
    $queue = 'browser-notification-'.$row->id;
    try {
        Queue::connection('redis')->push((new DeliverNotification($user->id, $org, $row->id))->onQueue($queue), '', $queue);
        Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $queue]);
    } finally {
        Queue::connection('redis')->clear($queue);
    }
}
echo json_encode(['processed' => count($rows)]);
