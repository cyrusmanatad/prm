<?php

use App\Services\NotificationOutbox;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
app(NotificationOutbox::class)->deliver((int) $argv[1], $argv[2], $argv[3]);
echo json_encode(['done' => true])."\n";
