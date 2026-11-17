<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PlatformAdministration
{
    public function run(Request $request, Closure $callback, bool $stepUp = true): mixed
    {
        try {
            return app(TenantContext::class)->run((int) $request->user()->id, null, function () use ($request, $callback, $stepUp) {
                $grant = DB::selectOne('SELECT * FROM app_lock_platform_grant()');
                abort_unless($grant && $request->user()->hasVerifiedEmail(), 403);
                $proof = $request->session()->get('platform_mfa');
                $verified = is_array($proof) && (int) ($proof['user'] ?? 0) === (int) $request->user()->id && (int) ($proof['version'] ?? 0) === (int) $grant->version && ($proof['until'] ?? 0) > time();
                // Presentation only: navigation hints for the shell. Every request is still authorized in PostgreSQL.
                Inertia::share('admin', ['capabilities' => array_values(array_filter(explode(',', trim((string) $grant->permissions, '{}')))), 'environment' => $this->environment(), 'verified' => $verified]);
                if ($stepUp) {
                    if (! $verified && $request->isMethod('GET') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
                        // An expired proof on a page visit returns to the challenge instead of a dead end.
                        $request->session()->put('platform_intended', '/'.ltrim($request->getRequestUri(), '/'));
                        throw new HttpResponseException(redirect('/admin/security'));
                    }
                    abort_unless($verified, 403, 'Verify your authenticator to continue.');
                    DB::select("SELECT set_config('app.admin_version',?,true)", [(string) $grant->version]);
                }

                return $callback($grant);
            });
        } catch (\Throwable $error) {
            app(TenantContext::class)->run((int) $request->user()->id, null, fn () => DB::select('SELECT app_platform_denied(?)', [$request->path()]));
            throw $error;
        }
    }

    /** Fresh proof for a sensitive change: current password and an unused authenticator code. Call inside run(). */
    public function confirm(Request $request, string $password, string $code): void
    {
        $user = DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Enter your current password.']);
        }
        abort_unless($user->admin_totp_secret, 403);
        $secret = decrypt($user->admin_totp_secret);
        foreach ([intdiv(time(), 30) - 1, intdiv(time(), 30), intdiv(time(), 30) + 1] as $counter) {
            if ($counter > ($user->admin_totp_counter ?? -1) && hash_equals($this->code($secret, $counter), $code)) {
                DB::table('users')->where('id', $user->id)->update(['admin_totp_counter' => $counter]);

                return;
            }
        }
        throw ValidationException::withMessages(['code' => 'Enter a fresh authenticator code.']);
    }

    public function environment(): string
    {
        return config('staging.enabled') ? 'Staging' : (app()->isProduction() ? 'Production' : 'Development');
    }

    /** Destination recorded when step-up interrupted an administration page visit. */
    public function intended(Request $request, string $fallback): string
    {
        $path = $request->session()->pull('platform_intended');

        return is_string($path) && preg_match('#^/admin(?:[/?]|$)#', $path) && ! str_starts_with($path, '/admin/security') ? $path : $fallback;
    }

    /** RFC 6238 SHA-1, six digits, thirty seconds; counters are consumed once. */
    public function code(string $secret, int $counter): string
    {
        $binary = '';
        foreach (str_split($secret) as $character) {
            $binary .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 15;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
