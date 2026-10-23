<?php

// CLI-only adversarial capability probe for a disposable acceptance database.
use App\Services\Onboarding;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Testing CLI only.');
}
echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
try {
    app(Onboarding::class)->publicRun($argv[1], fn () => null);
    echo "allowed\n";
} catch (HttpException $e) {
    echo 'denied:'.$e->getStatusCode()."\n";
}
