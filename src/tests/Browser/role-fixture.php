<?php

// CLI-only migration-role fixture. Never part of HTTP routes or the web role.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $email, $role] = $argv;
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing'])
    || ! preg_match('/^m1-browser-[a-z0-9-]+@example\.test$/', $email)
    || ! in_array($role, ['owner', 'admin', 'manager', 'member', 'viewer', 'revoked'], true)
    || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
    throw new RuntimeException('Only disposable migration-role browser fixtures are allowed.');
}
$user = User::where('email', $email)->firstOrFail();
DB::table('memberships')->where('user_id', $user->id)->update($role === 'revoked' ? ['revoked_at' => now()] : ['role' => $role]);
