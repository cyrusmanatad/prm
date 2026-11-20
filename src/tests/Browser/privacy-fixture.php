<?php

// Disposable migration-service fixture. Synthetic identities only; never a web endpoint.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $action, $email] = $argv;
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || ! preg_match('/^m1-browser-privacy-[a-z0-9-]+@example\.test$/D', $email)) {
    throw new RuntimeException('Only disposable privacy browser fixtures are allowed.');
}
if ($action === 'change') {
    DB::table('users')->where('email', $email)->update(['name' => 'Changed synthetic name']);
    echo "{}\n";
    exit;
}
$secret = 'JBSWY3DPEHPK3PXP';
$user = User::factory()->create(['name' => 'Privacy Reviewer', 'email' => $email, 'password' => 'browser-password-123',
    'admin_totp_secret' => $action === 'enrolled' ? encrypt($secret) : null, 'admin_totp_confirmed_at' => $action === 'enrolled' ? now() : null]);
$org = (string) Str::uuid();
DB::table('organizations')->insert(['id' => $org, 'name' => 'Privacy review workspace', 'kind' => 'creator', 'experience' => 'partner']);
DB::table('memberships')->insert(['org_id' => $org, 'user_id' => $user->id, 'role' => 'owner']);
DB::table('pipeline_entries')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'brand_name' => 'Synthetic archive client', 'currency' => 'PHP']);
echo json_encode(['org' => $org, 'secret' => $secret], JSON_THROW_ON_ERROR)."\n";
