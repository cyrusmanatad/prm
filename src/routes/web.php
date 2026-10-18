<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\RevokeSessionsController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Inertia pages and JSON clients share the same session-protected actions.
Route::get('/', fn (Request $request) => redirect($request->user()
    ? ($request->user()->hasVerifiedEmail() ? '/workspaces' : '/verify-email') : '/login'));
Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => Inertia::render('Auth', ['mode' => 'login']))->name('login');
    Route::get('/register', fn () => Inertia::render('Auth', ['mode' => 'register']));
    Route::get('/forgot-password', fn () => Inertia::render('Auth', ['mode' => 'forgot-password']));
    Route::get('/reset-password/{token}', function (Request $request, string $token) {
        $email = $request->query('email', '');

        return Inertia::render('Auth', [
            'mode' => 'reset-password', 'token' => $token,
            'email' => is_string($email) && strlen($email) <= 255 ? $email : '',
        ]);
    })->name('password.reset');
    Route::post('/register', RegisteredUserController::class)->middleware('throttle:5,1,register:');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:20,1,login:')->name('login.store');
    Route::post('/forgot-password', [PasswordController::class, 'sendLink'])->middleware('throttle:5,1,recovery:')->name('password.email');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1,reset:')->name('password.update');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::delete('/sessions', RevokeSessionsController::class)->middleware('throttle:5,1,sessions:');
    Route::get('/verify-email', fn (Request $request) => $request->user()->hasVerifiedEmail()
        ? redirect('/workspaces')
        : Inertia::render('Auth', ['mode' => 'verify-email', 'email' => $request->user()->email]))->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1,verification-link:'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1,verification-resend:')->name('verification.send');
    Route::get('/account', fn (Request $request) => response()->json([
        'user' => $request->user()->only(['id', 'name', 'email', 'email_verified_at']),
    ]));
    Route::middleware('verified')->group(function (): void {
        Route::middleware(WorkspaceContext::class)->group(function (): void {
            Route::get('/pipeline', [PipelineController::class, 'index']);
            Route::post('/pipeline', [PipelineController::class, 'store']);
            Route::patch('/pipeline/{entry}', [PipelineController::class, 'update'])->whereUuid('entry');
            Route::delete('/pipeline/{entry}', [PipelineController::class, 'destroy'])->whereUuid('entry');
            Route::post('/pipeline/{entry}/stage', [PipelineController::class, 'stage'])->whereUuid('entry');
            Route::post('/pipeline/{entry}/snooze', [PipelineController::class, 'snooze'])->whereUuid('entry');
            Route::post('/pipeline/{entry}/activities', [PipelineController::class, 'activity'])->whereUuid('entry');
        });
        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:5,1,workspace-create:');
        Route::post('/workspace/select', [WorkspaceController::class, 'select']);
        Route::patch('/workspace', [WorkspaceController::class, 'update'])->middleware(WorkspaceContext::class);
    });
});
