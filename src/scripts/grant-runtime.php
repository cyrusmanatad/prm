<?php

// Run in the migration container after migrations, with migration credentials.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::unprepared(file_get_contents(__DIR__.'/../database/sql/runtime-grants.sql'));
echo "Runtime privileges applied.\n";
