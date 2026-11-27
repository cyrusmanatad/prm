<?php

use App\Http\Controllers\Account\AppearanceController;
use App\Http\Controllers\Account\AuthenticatorController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\RevokeSessionsController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\BrandLeadController;
use App\Http\Controllers\BrandRequestController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceDraftController;
use App\Http\Controllers\LeadRegistrationController;
use App\Http\Controllers\ManualPaymentController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OutreachTemplateController;
use App\Http\Controllers\PartnerJoinController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PlatformBrandController;
use App\Http\Controllers\PlatformConsoleController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PlatformGovernanceController;
use App\Http\Controllers\PlatformNotificationController;
use App\Http\Controllers\PlatformPrivacyController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PrivacyClosureController;
use App\Http\Controllers\PrivacyExportController;
use App\Http\Controllers\ProfessionalController;
use App\Http\Controllers\PublicPresenceController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\WorkspaceContext;
use App\Services\PartnershipInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public pages exchange capabilities in POST bodies; fragment tokens never reach access logs.
Route::get('/join', [PartnerJoinController::class, 'page']);
Route::post('/join/prepare', [PartnerJoinController::class, 'prepare'])->middleware('throttle:10,1,join-prepare:');
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
    Route::get('/{portal}/login', fn (Request $request, string $portal) => Inertia::render('Auth', ['mode' => 'login', 'portal' => $portal, 'joining' => PartnershipInvitation::join($request)['brand'] ?? null]))->whereIn('portal', ['brand', 'partner', 'professional']);
    Route::get('/{portal}/register', fn (Request $request, string $portal) => Inertia::render('Auth', ['mode' => 'register', 'portal' => $portal, 'joining' => PartnershipInvitation::join($request)['brand'] ?? null]))->whereIn('portal', ['brand', 'partner', 'professional']);
    Route::post('/{portal}/login', [SessionController::class, 'store'])->whereIn('portal', ['brand', 'partner', 'professional'])->middleware('throttle:20,1,login:');
    Route::post('/{portal}/register', RegisteredUserController::class)->whereIn('portal', ['brand', 'partner', 'professional'])->middleware('throttle:5,1,register:');
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
        ? redirect($request->session()->has('partnership_join') ? '/portal/enter' : '/workspaces')
        : Inertia::render('Auth', ['mode' => 'verify-email', 'email' => $request->user()->email, 'joining' => PartnershipInvitation::join($request)['brand'] ?? null]))->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1,verification-link:'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1,verification-resend:')->name('verification.send');
    Route::get('/account', fn (Request $request) => response()->json([
        'user' => $request->user()->only(['id', 'name', 'email', 'email_verified_at']),
    ]));
    Route::middleware('verified')->group(function (): void {
        Route::get('/account/closure', [PrivacyClosureController::class, 'index']);
        Route::post('/account/closure', [PrivacyClosureController::class, 'store'])->middleware('throttle:5,1,privacy-close:');
        Route::post('/account/closure/{closure}/cancel', [PrivacyClosureController::class, 'cancel'])->whereUuid('closure')->middleware('throttle:5,1,privacy-cancel:');
        Route::get('/admin/privacy-requests', [PlatformPrivacyController::class, 'index']);
        Route::post('/admin/privacy-requests/{closure}', [PlatformPrivacyController::class, 'update'])->whereUuid('closure')->middleware('throttle:10,1,privacy-review:');
        Route::get('/account/exports', [PrivacyExportController::class, 'index']);
        Route::post('/account/exports/verify', [PrivacyExportController::class, 'verify'])->middleware('throttle:10,1,privacy-verify:');
        Route::post('/account/exports', [PrivacyExportController::class, 'store'])->middleware('throttle:3,60,privacy-export:');
        Route::get('/account/exports/{export}/download', [PrivacyExportController::class, 'download'])->whereUuid('export')->middleware('throttle:10,1,privacy-download:');
        Route::get('/account/appearance', [AppearanceController::class, 'show']);
        Route::post('/account/appearance', [AppearanceController::class, 'update'])->middleware('throttle:20,1,account-appearance:');
        Route::get('/account/authenticator', [AuthenticatorController::class, 'show']);
        Route::post('/account/authenticator/enroll', [AuthenticatorController::class, 'enroll'])->middleware('throttle:5,1,account-enroll:');
        Route::delete('/account/authenticator/enroll', [AuthenticatorController::class, 'cancel']);
        Route::post('/account/authenticator/verify', [AuthenticatorController::class, 'verify'])->middleware('throttle:10,1,account-mfa:');
        Route::post('/account/authenticator/recover', [AuthenticatorController::class, 'recover'])->middleware('throttle:10,1,account-mfa:');
        Route::post('/account/authenticator/recovery-codes', [AuthenticatorController::class, 'regenerate'])->middleware('throttle:5,1,account-codes:');
        Route::get('/admin/security', [PlatformController::class, 'security']);
        Route::post('/admin/security/enroll', [PlatformController::class, 'enroll'])->middleware('throttle:5,1,admin-enroll:');
        Route::post('/admin/security/verify', [PlatformController::class, 'verify'])->middleware('throttle:5,1,admin-mfa:');
        Route::get('/admin/notifications', [PlatformNotificationController::class, 'index']);
        Route::post('/admin/notifications/{notification}/resolve', [PlatformNotificationController::class, 'resolve'])->whereUuid('notification')->middleware('throttle:10,1,notification-resolution:');
        Route::get('/admin', [PlatformController::class, 'index']);
        Route::get('/admin/accounts', [PlatformConsoleController::class, 'accounts']);
        Route::get('/admin/accounts/{account}', [PlatformConsoleController::class, 'account'])->whereNumber('account');
        Route::get('/admin/workspaces', [PlatformConsoleController::class, 'workspaces']);
        Route::get('/admin/workspaces/{workspace}', [PlatformConsoleController::class, 'workspace'])->whereUuid('workspace');
        Route::get('/admin/plans', [PlatformConsoleController::class, 'plans']);
        Route::get('/admin/plans/{plan}', [PlatformConsoleController::class, 'plan'])->whereUuid('plan');
        Route::get('/admin/assignments', [PlatformConsoleController::class, 'assignments']);
        Route::get('/admin/assignments/create', [PlatformConsoleController::class, 'assignmentForm']);
        Route::get('/admin/notifications/{notification}', [PlatformConsoleController::class, 'notification'])->whereUuid('notification');
        Route::get('/admin/operations', [PlatformConsoleController::class, 'operations']);
        Route::get('/admin/audit', [PlatformConsoleController::class, 'audit']);
        Route::get('/admin/audit/{source}/{event}', [PlatformConsoleController::class, 'auditEvent'])->whereIn('source', ['platform', 'local-pilot'])->where('event', '[0-9a-f-]{1,36}');
        Route::get('/admin/operators', [PlatformConsoleController::class, 'operators']);
        Route::post('/admin/operators', [PlatformConsoleController::class, 'saveOperator'])->middleware('throttle:10,1,admin-operators:');
        Route::post('/admin/{kind}/{reference}/{action}', [PlatformGovernanceController::class, 'access'])->whereIn('kind', ['accounts', 'workspaces'])->where('reference', '[0-9a-f-]{1,36}')->whereIn('action', ['suspend', 'reactivate'])->middleware('throttle:10,1,admin-access:');
        Route::post('/admin/accounts/{account}/sign-out', [PlatformGovernanceController::class, 'signOut'])->whereNumber('account')->middleware('throttle:10,1,admin-access:');
        Route::post('/admin/accounts/{account}/reset-authenticator', [PlatformGovernanceController::class, 'resetAuthenticator'])->whereNumber('account')->middleware('throttle:10,1,admin-access:');
        Route::get('/admin/support-access', [PlatformGovernanceController::class, 'support']);
        Route::post('/admin/support-access', [PlatformGovernanceController::class, 'requestSupport'])->middleware('throttle:30,1,admin-support:');
        Route::get('/admin/support-access/{grant}', [PlatformGovernanceController::class, 'supportGrant'])->whereUuid('grant');
        Route::post('/admin/support-access/{grant}/end', [PlatformGovernanceController::class, 'endSupport'])->whereUuid('grant')->middleware('throttle:30,1,admin-support:');
        Route::get('/admin/cleanup', [PlatformGovernanceController::class, 'cleanup']);
        Route::post('/admin/cleanup', [PlatformGovernanceController::class, 'createBatch'])->middleware('throttle:10,1,admin-cleanup:');
        Route::get('/admin/cleanup/runs/{run}', [PlatformGovernanceController::class, 'run'])->whereUuid('run');
        Route::get('/admin/cleanup/{batch}', [PlatformGovernanceController::class, 'batch'])->whereUuid('batch');
        Route::post('/admin/cleanup/{batch}/marks', [PlatformGovernanceController::class, 'mark'])->whereUuid('batch')->middleware('throttle:60,1,admin-cleanup-mark:');
        Route::post('/admin/cleanup/{batch}/runs', [PlatformGovernanceController::class, 'dryRun'])->whereUuid('batch')->middleware('throttle:10,1,admin-cleanup:');
        Route::post('/admin/assignments', [PlatformController::class, 'assign'])->middleware('throttle:10,1,admin-assign:');
        Route::get('/admin/brand-requests', [PlatformBrandController::class, 'index']);
        Route::get('/admin/brand-requests/{brandRequest}', [PlatformBrandController::class, 'show'])->whereUuid('brandRequest');
        Route::post('/admin/brand-requests/{brandRequest}/decision', [PlatformBrandController::class, 'decide'])->whereUuid('brandRequest')->middleware('throttle:30,1,admin-brand-decision:');
        // Brand workspaces are created only by an approved request; applicants see only their own requests.
        Route::get('/brand/requests', [BrandRequestController::class, 'index']);
        Route::post('/brand/requests', [BrandRequestController::class, 'store'])->middleware('throttle:10,1,brand-request:');
        Route::get('/brand/requests/{brandRequest}', [BrandRequestController::class, 'show'])->whereUuid('brandRequest');
        Route::patch('/brand/requests/{brandRequest}', [BrandRequestController::class, 'update'])->whereUuid('brandRequest')->middleware('throttle:10,1,brand-request:');
        Route::get('/join/select', [PartnerJoinController::class, 'select']);
        Route::get('/portal/enter', [PortalController::class, 'enter']);

        Route::middleware(WorkspaceContext::class)->group(function (): void {
            Route::get('/workspace/support-access', [PlatformGovernanceController::class, 'workspaceSupport']);
            Route::post('/workspace/support-access/{grant}', [PlatformGovernanceController::class, 'decideSupport'])->whereUuid('grant')->middleware('throttle:30,1,workspace-support:');
            // Home is a separate destination per experience; existing hubs and landing redirects are unchanged.
            Route::get('/{experience}/home', [HomeController::class, 'index'])->whereIn('experience', ['brand', 'partner', 'professional']);
            Route::get('/professional', [ProfessionalController::class, 'index']);
            Route::get('/professional/exports/{resource}', [ProfessionalController::class, 'export'])->whereIn('resource', ['leads', 'contacts', 'reminders', 'invoices', 'payments']);
            Route::get('/professional/leads', [PortalController::class, 'index']);
            Route::get('/professional/contacts', [CrmController::class, 'index']);
            Route::get('/professional/onboarding', [OnboardingController::class, 'index']);
            Route::get('/professional/profile', [PublicPresenceController::class, 'index']);
            Route::get('/professional/brand-sharing', [LeadRegistrationController::class, 'index']);
            Route::get('/professional/outreach-templates', [OutreachTemplateController::class, 'index']);
            Route::get('/professional/automation', [AutomationController::class, 'index']);
            Route::get('/professional/finance/invoices', [InvoiceDraftController::class, 'index']);
            Route::get('/professional/finance/payments', [ManualPaymentController::class, 'index']);
            Route::get('/automation/drafts/{draft}/handoff', [FollowUpController::class, 'handoff'])->whereUuid('draft');
            Route::post('/automation/drafts', [FollowUpController::class, 'save']);
            Route::patch('/automation/drafts/{draft}', [FollowUpController::class, 'save'])->whereUuid('draft');
            Route::post('/automation/drafts/{draft}/send', [FollowUpController::class, 'send'])->whereUuid('draft')->middleware('throttle:10,1,follow-up-send:');
            Route::post('/automation/drafts/{draft}/{action}', [FollowUpController::class, 'transition'])->whereUuid('draft')->whereIn('action', ['approve', 'discard']);
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

            Route::get('/{experience}/campaigns/{campaign?}', [CampaignController::class, 'index'])->whereIn('experience', ['brand', 'partner'])->whereUuid('campaign');
            Route::post('/brand/campaigns', [CampaignController::class, 'store'])->middleware('throttle:30,1,campaign-create:');
            Route::patch('/brand/campaigns/{campaign}/{action}', [CampaignController::class, 'update'])->whereUuid('campaign')->whereIn('action', ['draft', 'share'])->middleware('throttle:60,1,campaign-update:');
            Route::get('/partner/leads', [PortalController::class, 'index']);
            Route::get('/partner', [PortalController::class, 'index']);
            Route::post('/join/accept', [PartnerJoinController::class, 'accept'])->middleware('throttle:6,1,join-accept:');
            Route::post('/partner-join-link', [PartnerJoinController::class, 'issue'])->middleware('throttle:10,1,join-manage:');
            Route::delete('/partner-join-link', [PartnerJoinController::class, 'disable'])->middleware('throttle:10,1,join-disable:');
            Route::get('/brand', [PortalController::class, 'index']);
            // Brand lead visibility (D38). Brands read only explicitly registered leads; Partners publish and withdraw them.
            Route::get('/brand/partners/{relationship}', [BrandLeadController::class, 'partner'])->whereUuid('relationship');
            Route::get('/brand/lead-registrations', [BrandLeadController::class, 'index']);
            Route::get('/brand/lead-registrations/{registration}', [BrandLeadController::class, 'show'])->whereUuid('registration');
            Route::get('/partner/brand-sharing', [LeadRegistrationController::class, 'index']);
            Route::post('/lead-registrations', [LeadRegistrationController::class, 'store'])->middleware('throttle:30,1,lead-registration:');
            Route::patch('/lead-registrations/{registration}', [LeadRegistrationController::class, 'update'])->whereUuid('registration')->middleware('throttle:30,1,lead-registration:');
            Route::delete('/lead-registrations/{registration}', [LeadRegistrationController::class, 'destroy'])->whereUuid('registration')->middleware('throttle:30,1,lead-registration:');
            Route::put('/partner-shared-profile', [LeadRegistrationController::class, 'profile'])->middleware('throttle:30,1,shared-profile:');
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
            Route::get('/crm/exports/{resource}', [CrmController::class, 'export'])->whereIn('resource', ['contacts', 'reminders'])->middleware('throttle:10,1,crm-export:');
            Route::post('/contacts/bulk', [CrmController::class, 'bulk'])->middleware('throttle:30,1,crm-bulk:');
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
