<?php

// CLI-only expiry rehearsal for this test's synthetic identity; never an HTTP endpoint.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || ! preg_match('/^m1-browser-join-existing-[a-z0-9-]+@example\.test$/', $email)) {
    throw new RuntimeException('Synthetic local join identity required.');
}
$user = User::where('email', $email)->firstOrFail();
foreach (DB::table('sessions')->where('user_id', $user->id)->get() as $session) {
    $data = json_decode(base64_decode($session->payload), true, flags: JSON_THROW_ON_ERROR);
    if (isset($data['partnership_join'])) {
        $data['partnership_join']['expires'] = time() - 1;
        DB::table('sessions')->where('id', $session->id)->update(['payload' => base64_encode(json_encode($data, JSON_THROW_ON_ERROR))]);
    }
}
echo "Expired synthetic join context.\n";
