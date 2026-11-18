<?php

// CLI-only migration-role fixture: a synthetic approved Brand workspace for an existing browser identity.
// Brand workspaces need recorded approval (D37), so journeys that only need a Brand workspace use this
// instead of the review flow. Never part of HTTP routes or the web role.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BrandFixture;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $email, $name] = $argv + [null, '', ''];
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing'])
    || ! preg_match('/^m1-browser-[a-z0-9-]+@example\.test$/', $email) || trim($name) === '' || strlen($name) > 120
    || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
    throw new RuntimeException('Only disposable migration-role browser fixtures are allowed.');
}
echo json_encode(['org' => BrandFixture::create(User::where('email', $email)->firstOrFail()->id, $name, DB::connection())]);
