<?php

// CLI-only migration-role fixture for synthetic local browser identities.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || ! preg_match('/^m1-browser-finance-[a-z0-9-]+@example\.test$/', $email)
    || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
    throw new RuntimeException('Only local migration-role synthetic finance fixtures are allowed.');
}
$user = User::where('email', $email)->firstOrFail();
$org = DB::table('memberships')->where('user_id', $user->id)->where('role', 'owner')->value('org_id');
Artisan::call('pilot:plan', ['name' => $email, 'version' => 1, 'quotas' => '{"invoice_drafts":0}', '--operator' => 'browser-fixture', '--reason' => 'Synthetic quota journey']);
$plan = trim(Artisan::output());
Artisan::call('pilot:assign', ['workspace' => $org, 'plan' => $plan, 'starts' => now()->subMinute()->format('Y-m-d\TH:i:sP'), 'ends' => now()->addDay()->format('Y-m-d\TH:i:sP'), '--operator' => 'browser-fixture', '--reason' => 'Synthetic quota journey']);
echo json_encode(['plan' => $plan, 'org' => $org]);
