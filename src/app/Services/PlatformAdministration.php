<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformAdministration
{
    public function run(Request $request, Closure $callback, bool $stepUp = true): mixed
    {
        try {
            return app(TenantContext::class)->run((int) $request->user()->id, null, function () use ($request, $callback, $stepUp) {
                $grant = DB::selectOne('SELECT * FROM app_lock_platform_grant()');
                abort_unless($grant && $request->user()->hasVerifiedEmail(), 403);
                if ($stepUp) {
                    $proof = $request->session()->get('platform_mfa');
                    abort_unless(is_array($proof) && (int) ($proof['user'] ?? 0) === (int) $request->user()->id && (int) ($proof['version'] ?? 0) === (int) $grant->version && ($proof['until'] ?? 0) > time(), 403, 'Verify your authenticator to continue.');
                    DB::select("SELECT set_config('app.admin_version',?,true)", [(string) $grant->version]);
                }

                return $callback($grant);
            });
        } catch (\Throwable $error) {
            app(TenantContext::class)->run((int) $request->user()->id, null, fn () => DB::select('SELECT app_platform_denied(?)', [$request->path()]));
            throw $error;
        }
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
