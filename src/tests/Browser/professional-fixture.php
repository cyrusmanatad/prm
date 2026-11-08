<?php

use App\Models\User;
use App\Services\Workspaces;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator' || ! preg_match('/^m1-browser-professional-[a-z0-9-]+@example\.test$/', $email)) {
    throw new RuntimeException('Disposable migration-role fixture required.');
}
$user = User::factory()->create(['name' => 'Independent Professional', 'email' => $email, 'password' => 'browser-password-123']);
// Workspace creation deliberately requires the restricted runtime role.
config(['database.connections.browser_runtime' => [...config('database.connections.pgsql'), 'username' => 'weavdash_app', 'password' => getenv('APP_DB_PASSWORD') ?: getenv('BROWSER_RUNTIME_PASSWORD')]]);
// Compose migrate overrides DB_PASSWORD. Read the supplied .env only inside this CLI fixture.
$environment = Dotenv::parse(file_get_contents(base_path('.env')));
config(['database.connections.browser_runtime.password' => $environment['DB_PASSWORD']]);
DB::setDefaultConnection('browser_runtime');
$org = app(Workspaces::class)->create($user->id, $argv[2] ?? 'Independent consulting', experience: 'professional');
DB::setDefaultConnection('pgsql');
$plan = (string) Str::uuid();
DB::table('pilot_plan_versions')->insert(['id' => $plan, 'name' => $plan, 'version' => 1, 'quotas' => json_encode(array_fill_keys(['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'], 50))]);
DB::table('pilot_assignments')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => $plan, 'version' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
DB::table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => '{platform.owner}']);
echo json_encode(['org' => $org]);
