<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Synthetic platform owner, a second verified identity and one failed job for the administration journey.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || config('mail.default') !== 'array'
    || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator'
    || ! preg_match('/^m1-browser-admin-[a-z0-9-]+@example\.test$/', $email)) {
    throw new RuntimeException('Synthetic local/array-mail migration-role fixture required.');
}
$user = User::where('email', $email)->firstOrFail();
if (($argv[2] ?? '') === 'release') {
    // After a test, remove only the grants it created, so the unpaginated operator list stays small on long-lived local stacks.
    $ids = User::whereIn('email', [$email, str_replace('m1-browser-admin-', 'm1-browser-admin-peer-', $email)])->pluck('id');
    echo json_encode(['released' => DB::table('platform_grants')->whereIn('user_id', $ids)->delete()]);

    return;
}
$secret = 'JBSWY3DPEHPK3PXP';
DB::table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => '{platform.owner}']);
DB::table('users')->where('id', $user->id)->update(['admin_totp_secret' => encrypt($secret), 'admin_totp_confirmed_at' => now()]);
$peer = User::forceCreate(['name' => 'Ari Cruz Synthetic Operator', 'email' => str_replace('m1-browser-admin-', 'm1-browser-admin-peer-', $email),
    'password' => Hash::make('browser-password-123'), 'email_verified_at' => now()]);
// The payload and exception carry markers that must never reach the page.
DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
    'payload' => json_encode(['displayName' => 'App\\Jobs\\SendNotification', 'data' => ['secret' => 'PRIVATE-PAYLOAD-MARKER']]),
    'exception' => "RuntimeException: PRIVATE-EXCEPTION-MARKER in /var/www/html/app/Jobs/SendNotification.php:12\nStack trace", 'failed_at' => now()]);
echo json_encode(['secret' => $secret, 'peer' => $peer->id, 'peerEmail' => $peer->email, 'owner' => $user->id]);
