<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Ends any session that outlives an account suspension, such as one created by a concurrent sign-in. */
class EnforceAccountAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && DB::getDriverName() === 'pgsql' && DB::selectOne('SELECT app_account_suspended(?) AS suspended', [$user->id])->suspended) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = 'This account\'s access is suspended. Contact support.';

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $message], 403)
                : redirect('/login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
