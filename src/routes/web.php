<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\RevokeSessionsController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\InvoiceDraftController;
use App\Http\Controllers\ManualPaymentController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OutreachTemplateController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PublicPresenceController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public pages exchange capabilities in POST bodies; fragment tokens never reach access logs.
Route::get('/partnerships/accept', [PortalController::class, 'acceptancePage']);
Route::post('/partnerships/prepare', [PortalController::class, 'prepare'])->middleware('throttle:10,1,partner-prepare:');
Route::get('/onboarding', fn () => Inertia::render('OpenInvitation'));
Route::post('/onboarding/session', [OnboardingController::class, 'exchange'])->middleware('throttle:6,1,onboarding-exchange:');
Route::get('/onboarding/form/{record}', [OnboardingController::class, 'form'])->whereUuid('record');
Route::patch('/onboarding/form/{record}', [OnboardingController::class, 'save'])->whereUuid('record')->middleware('throttle:30,1,onboarding-save:');
Route::post('/onboarding/form/{record}/submit', [OnboardingController::class, 'submit'])->whereUuid('record')->middleware('throttle:15,1,onboarding-submit:');
Route::post('/onboarding/form/{record}/documents', [OnboardingController::class, 'upload'])->whereUuid('record')->middleware('throttle:10,1,onboarding-upload:');
Route::get('/onboarding/form/{record}/documents/{document}', [OnboardingController::class, 'document'])->whereUuid('record')->whereUuid('document');
Route::delete('/onboarding/form/{record}/documents/{document}', [OnboardingController::class, 'removeDocument'])->whereUuid('record')->whereUuid('document');
Route::get('/lead', fn () => Inertia::render('PublicLead'));
Route::post('/lead', [PublicPresenceController::class, 'capture'])->middleware('throttle:5,1,public-lead:');
Route::get('/kits/{record}', [PublicPresenceController::class, 'kit'])->whereUuid('record')->middleware('throttle:60,1,public-kit:');
// Inertia pages and JSON clients share the same session-protected actions.
Route::get('/', fn (Request $request) => redirect($request->user()
    ? ($request->user()->hasVerifiedEmail() ? '/workspaces' : '/verify-email') : '/login'));
Route::middleware('guest')->group(function (): void {
    Route::get('/{portal}/login', fn (string $portal) => Inertia::render('Auth', ['mode' => 'login', 'portal' => $portal]))->whereIn('portal', ['brand', 'partner']);
    Route::get('/{portal}/register', fn (string $portal) => Inertia::render('Auth', ['mode' => 'register', 'portal' => $portal]))->whereIn('portal', ['brand', 'partner']);
    Route::post('/{portal}/login', [SessionController::class, 'store'])->whereIn('portal', ['brand', 'partner'])->middleware('throttle:20,1,login:');
    Route::post('/{portal}/register', RegisteredUserController::class)->whereIn('portal', ['brand', 'partner'])->middleware('throttle:5,1,register:');
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
        Route::get('/portal/enter', [PortalController::class, 'enter']);

        Route::middleware(WorkspaceContext::class)->group(function (): void {
            Route::get('/automation', [AutomationController::class, 'index']);
            Route::put('/automation/preferences', [AutomationController::class, 'preferences']);
            Route::post('/automation/schedules', [AutomationController::class, 'save']);
            Route::patch('/automation/schedules/{schedule}', [AutomationController::class, 'save'])->whereUuid('schedule');
            Route::delete('/automation/schedules/{schedule}', [AutomationController::class, 'revoke'])->whereUuid('schedule');

            Route::get('/workspace/entitlements', [InvoiceDraftController::class, 'entitlements']);
            Route::get('/finance/payments', [ManualPaymentController::class, 'index']);
            Route::post('/finance/payments', [ManualPaymentController::class, 'mutate']);
            Route::post('/finance/payments/{payment}/{action}', [ManualPaymentController::class, 'mutate'])->whereUuid('payment')->whereIn('action', ['allocate', 'void']);
            Route::post('/finance/allocations/{payment}/{action}', [ManualPaymentController::class, 'mutate'])->whereUuid('payment')->whereIn('action', ['reverse']);
            Route::get('/finance/invoices', [InvoiceDraftController::class, 'index']);
            Route::get('/finance/invoices/{invoice}', [InvoiceDraftController::class, 'index'])->whereUuid('invoice');
            Route::post('/finance/invoices', [InvoiceDraftController::class, 'mutate']);
            Route::patch('/finance/invoices/{invoice}', [InvoiceDraftController::class, 'mutate'])->whereUuid('invoice');
            Route::post('/finance/invoices/{invoice}/void', [InvoiceDraftController::class, 'mutate'])->whereUuid('invoice');

            Route::get('/partner/leads', [PortalController::class, 'index']);
            Route::get('/partner', [PortalController::class, 'index']);
            Route::get('/brand', [PortalController::class, 'index']);
            Route::post('/partner-invitations', [PortalController::class, 'invite'])->middleware('throttle:10,1,partner-invite:');
            Route::post('/partnerships/accept', [PortalController::class, 'accept'])->middleware('throttle:6,1,partner-accept:');
            Route::delete('/partner-invitations/{record}', [PortalController::class, 'revoke'])->whereUuid('record');
            Route::delete('/partnerships/{record}', [PortalController::class, 'revoke'])->whereUuid('record');
            Route::get('/partner/media-kit', [PublicPresenceController::class, 'index']);
            Route::get('/public-presence', [PublicPresenceController::class, 'index']);
            Route::put('/media-kit', [PublicPresenceController::class, 'saveKit']);
            Route::post('/lead-links', [PublicPresenceController::class, 'createLink']);
            Route::delete('/lead-links/{record}', [PublicPresenceController::class, 'revoke'])->whereUuid('record');
            Route::get('/partner/onboarding', [OnboardingController::class, 'index']);
            Route::get('/onboarding-requests', [OnboardingController::class, 'index']);
            Route::post('/onboarding-requests', [OnboardingController::class, 'store']);
            Route::post('/onboarding-requests/{record}/{action}', [OnboardingController::class, 'review'])->whereUuid('record')->whereIn('action', ['correct', 'accept', 'revoke', 'renew']);
            Route::match(['get', 'post'], '/onboarding-requests/{record}/documents/{document}', [OnboardingController::class, 'ownerDocument'])->whereUuid('record')->whereUuid('document');
            Route::get('/partner/contacts', [CrmController::class, 'index']);
            Route::get('/crm', [CrmController::class, 'index']);
            Route::post('/{kind}', [CrmController::class, 'store'])->whereIn('kind', ['contacts', 'reminders']);
            Route::patch('/{kind}/{record}', [CrmController::class, 'update'])->whereIn('kind', ['contacts', 'reminders'])->whereUuid('record');
            Route::delete('/{kind}/{record}', [CrmController::class, 'destroy'])->whereIn('kind', ['contacts', 'reminders'])->whereUuid('record');
            Route::post('/reminders/{record}/{action}', [CrmController::class, 'command'])->whereUuid('record')->whereIn('action', ['complete', 'reopen', 'snooze']);
            Route::match(['post', 'delete'], '/pipeline/{entry}/contacts', [CrmController::class, 'link'])->whereUuid('entry');
            Route::get('/partner/outreach-templates', [OutreachTemplateController::class, 'index']);
            Route::get('/outreach-templates', [OutreachTemplateController::class, 'index']);
            Route::post('/outreach-templates', [OutreachTemplateController::class, 'store']);
            Route::patch('/outreach-templates/{template}', [OutreachTemplateController::class, 'update'])->whereUuid('template');
            Route::delete('/outreach-templates/{template}', [OutreachTemplateController::class, 'destroy'])->whereUuid('template');
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
