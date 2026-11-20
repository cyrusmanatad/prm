<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $mode, $email] = $argv;
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || ! preg_match('/^m1-browser-closure-[a-z0-9-]+@example\.test$/D', $email)) {
    throw new RuntimeException('Only disposable closure browser fixtures are allowed.');
}
$secret = 'JBSWY3DPEHPK3PXP';
$user = User::factory()->create(['name' => 'Closure Reviewer', 'email' => $email, 'password' => 'browser-password-123',
    'admin_totp_secret' => $mode === 'personal' ? null : encrypt($secret), 'admin_totp_confirmed_at' => $mode === 'personal' ? null : now()]);
$result = ['secret' => $secret];
if ($mode === 'workspace') {
    $org = (string) Str::uuid();
    $kit = (string) Str::uuid();
    DB::table('organizations')->insert(['id' => $org, 'name' => 'Closure review workspace', 'kind' => 'creator']);
    DB::table('memberships')->insert(['org_id' => $org, 'user_id' => $user->id, 'role' => 'owner']);
    DB::table('pipeline_entries')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'brand_name' => 'Retained browser lead', 'currency' => 'PHP']);
    DB::table('media_kits')->insert(['id' => $kit, 'org_id' => $org, 'title' => 'Closed browser kit', 'published' => true, 'consented_at' => now()]);
    $result += ['org' => $org, 'kit' => $kit];
}
if ($mode === 'operator') {
    DB::table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => '{platform.owner}']);
    $subject = User::factory()->create(['name' => 'Privacy request '.Str::random(6), 'email' => 'synthetic-closure-subject-'.Str::uuid().'@example.test']);
    $id = (string) Str::uuid();
    DB::transaction(function () use ($subject, $id) {
        DB::selectOne("SELECT set_config('app.actor_id',?,true)", [(string) $subject->id]);
        $preview = json_decode(DB::selectOne("SELECT app_privacy_preview('account',NULL) AS data")->data, true);
        DB::select("SELECT app_privacy_close(?::uuid,'account',NULL,?,?)", [$id, $preview['hash'], 'Synthetic browser privacy review']);
    });
    $result += ['request' => $id, 'label' => $subject->name, 'page' => (int) ceil(DB::table('privacy_closure_requests')->where('state', 'cooling_off')->count() / 20)];
}
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
