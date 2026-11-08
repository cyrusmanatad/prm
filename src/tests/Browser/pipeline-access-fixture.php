<?php

// CLI-only synthetic subscription-expiry fixture; never exposed through HTTP.
use App\Models\User;
use App\Services\Pipeline;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
$state = $argv[2] ?? 'expired';
if (! in_array($state, ['active', 'expired'], true) || PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing'])
    || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator'
    || ! preg_match('/^m1-browser-drag-expired-[a-z0-9-]+@example\.test$/', $email)) {
    throw new RuntimeException('Disposable migration-role fixture required.');
}
$user = User::factory()->create(['email' => $email, 'password' => 'browser-password-123']);
$environment = Dotenv::parse(file_get_contents(base_path('.env')));
config(['database.connections.browser_runtime' => [...config('database.connections.pgsql'), 'username' => 'weavdash_app', 'password' => $environment['DB_PASSWORD']]]);
DB::setDefaultConnection('browser_runtime');
$org = app(Workspaces::class)->create($user->id, 'Professional drag workspace', experience: 'professional');
DB::setDefaultConnection('pgsql');
$plan = (string) Str::uuid();
DB::table('pilot_plan_versions')->insert(['id' => $plan, 'name' => $plan, 'version' => 1, 'quotas' => json_encode(array_fill_keys(['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'], 50))]);
DB::table('pilot_assignments')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => $plan, 'version' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
DB::setDefaultConnection('browser_runtime');
$id = app(TenantContext::class)->run($user->id, $org, fn () => app(Pipeline::class)->create($user->id, $org, ['brand_name' => 'Saved client', 'currency' => 'USD', 'value_minor' => '100']));
DB::setDefaultConnection('pgsql');
if ($state === 'expired') {
    DB::table('pilot_assignments')->where('org_id', $org)->update(['ends_at' => now()->subMinute()]);
}
echo json_encode(['org' => $org, 'id' => $id], JSON_THROW_ON_ERROR);
