<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_app') {
    throw new RuntimeException('Testing runtime role required.');
}
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
echo DB::selectOne('SELECT app_mail_budget_reserve(?,1) AS data', [$argv[1]])->data."\n";
