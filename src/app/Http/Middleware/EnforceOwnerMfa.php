<?php

namespace App\Http\Middleware;

use App\Services\Authenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace owners reach nothing but the authenticator page, sign-out and email verification until this
 * session has passed the authenticator check (D18, D34, D35). Every sign-in entrance, registration, sessions
 * that predate enforcement and sessions that become an owner all pass through here.
 */
class EnforceOwnerMfa
{
    public const MESSAGE = 'Verify your authenticator to continue.';

    public function __construct(private Authenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->authenticator->enforced() || $request->is('logout', 'account/authenticator', 'account/authenticator/*', 'verify-email', 'verify-email/*', 'email/verification-notification')
            || ! $this->authenticator->required((int) $user->id)) {
            return $next($request);
        }
        if ($user->admin_totp_confirmed_at !== null && $this->authenticator->proven($request)) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE], 403);
        }
        if ($request->isMethod('GET')) {
            $request->session()->put('account_mfa_intended', '/'.ltrim($request->getRequestUri(), '/'));
        }

        return redirect('/account/authenticator');
    }
}
