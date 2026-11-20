<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** A closing account may sign in only to view/cancel closure, pass MFA or sign out. */
class EnforcePrivacyClosure
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || DB::getDriverName() !== 'pgsql'
            || ! DB::selectOne('SELECT app_account_closing(?) AS closing', [$request->user()->id])->closing
            || $request->is('logout', 'account/closure', 'account/closure/*', 'account/authenticator', 'account/authenticator/*', 'login', 'brand/login', 'partner/login', 'professional/login')) {
            return $next($request);
        }

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => 'This account has a closure request. Review or cancel it from Account closure.'], 403)
            : redirect('/account/closure');
    }
}
