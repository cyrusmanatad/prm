<?php

// Migration-role probe for the staging seeder. Use only against a disposable testing database.
use App\Services\PresentationRegistrations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('testing')
    || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
    throw new RuntimeException('Testing migration role required.');
}
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
try {
    $status = app(PresentationRegistrations::class)->provision()['action'];
} catch (RuntimeException $error) {
    if (! str_starts_with($error->getMessage(), 'Partnership is not active for ')) {
        throw $error;
    }
    $status = 'refused';
}
echo json_encode(['status' => $status])."\n";
