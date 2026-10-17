<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\RevokeSessionsController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Foundation service endpoints use the web session and CSRF protection.
// Vue/Inertia page integration is pending installation of its uncached packages.
Route::get('/', fn () => response()->json(['application' => 'WeavDash', 'milestone' => 'M1 in progress']));
Route::middleware('guest')->group(function (): void {
    Route::post('/register', RegisteredUserController::class)->middleware('throttle:5,1');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:20,1')->name('login');
    Route::post('/forgot-password', [PasswordController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});
// Named destination for Laravel's reset notification until the Inertia reset page lands.
Route::get('/reset-password/{token}', fn () => response()->json(['message' => 'Password-reset UI is not installed yet.'], 503))->name('password.reset');
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::delete('/sessions', RevokeSessionsController::class)->middleware('throttle:5,1');
    Route::get('/verify-email', fn () => response()->json(['message' => 'Verify your email before creating a workspace.']))->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('/account', fn (Request $request) => response()->json([
        'user' => $request->user()->only(['id', 'name', 'email', 'email_verified_at']),
    ]));
    Route::middleware('verified')->group(function (): void {
        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:5,1');
        Route::post('/workspace/select', [WorkspaceController::class, 'select']);
        Route::patch('/workspace', [WorkspaceController::class, 'update'])->middleware(WorkspaceContext::class);
    });
});
