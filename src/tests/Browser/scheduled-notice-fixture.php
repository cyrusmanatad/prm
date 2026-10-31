<?php

// Migration-role fixture only: mature this synthetic actor's schedules, never shared records.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || config('mail.default') !== 'array'
    || ! preg_match('/^m1-browser-scheduled-[a-z0-9-]+@example\.test$/', $email)
    || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
    throw new RuntimeException('Only synthetic local schedule maturation is allowed.');
}
$user = User::where('email', $email)->firstOrFail();
$rows = DB::table('business_schedules')->where('actor_id', $user->id)->where('enabled', true)->get();
foreach ($rows as $s) {
    DB::table('business_schedules')->where('id', $s->id)->update(['next_due_at' => now()->subMinute()]);
}
echo json_encode(['count' => count($rows)]);
