<?php

// CLI-only support for a disposable local/CI database; never an HTTP endpoint.
use App\Models\User;
use App\Services\Workspaces;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Browser fixtures require a disposable local/testing CLI environment.');
}
[$script, $action, $email] = $argv;
if (! preg_match('/^m1-browser-[a-z0-9-]+@example\.test$/', $email)) {
    throw new RuntimeException('Only synthetic browser identities are allowed.');
}
if (in_array($action, ['seed', 'seed-workspaces', 'seed-unverified'], true)) {
    $user = User::factory()->create([
        'name' => 'Browser Creator', 'email' => $email, 'password' => 'browser-password-123',
        'email_verified_at' => $action === 'seed-unverified' ? null : now(),
    ]);
    $result = ['email' => $email];
    if ($action === 'seed-workspaces') {
        $result['own'] = app(Workspaces::class)->create($user->id, 'Role test workspace');
        $other = User::factory()->create();
        $result['foreign'] = app(Workspaces::class)->create($other->id, 'Hidden foreign workspace');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} else {
    $user = User::where('email', $email)->firstOrFail();
    $url = match ($action) {
        'verify' => URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]),
        'reset' => route('password.reset', ['token' => Password::createToken($user), 'email' => $email]),
        default => throw new RuntimeException('Unknown browser fixture action.'),
    };
    echo json_encode(['url' => $url], JSON_THROW_ON_ERROR);
}
