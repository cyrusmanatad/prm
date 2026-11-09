<?php

use App\Models\User;
use App\Services\PlatformAdministration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || config('mail.default') !== 'array'
    || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator'
    || ! preg_match('/^m1-browser-delivery-[a-z0-9-]+@example\.test$/', $email)) {
    throw new RuntimeException('Synthetic local/array-mail migration-role fixture required.');
}
$user = User::where('email', $email)->firstOrFail();
DB::table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => ($argv[2] ?? '') === 'directory' ? '{directory.read,notifications.read}' : '{notifications.read,notifications.reconcile}']);
$secret = 'JBSWY3DPEHPK3PXP';
DB::table('users')->where('id', $user->id)->update(['admin_totp_secret' => encrypt($secret), 'admin_totp_confirmed_at' => now()]);
DB::table('notification_outbox')->where('actor_id', $user->id)->where('kind', 'follow_up.outreach')->where('state', 'pending')->update(['state' => 'uncertain', 'encrypted_payload' => null, 'started_at' => now()]);
echo json_encode(['code' => app(PlatformAdministration::class)->code($secret, intdiv(time(), 30))]);
