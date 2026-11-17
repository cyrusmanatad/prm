<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** One authenticator per account (D34), shared by workspace-owner sign-in and platform step-up. */
class Authenticator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Production always enforces workspace-owner MFA; the setting applies to other environments only (D35). */
    public function enforced(): bool
    {
        return (app()->isProduction() && ! config('staging.enabled')) || (bool) config('security.owner_mfa');
    }

    /** True while the account owns a workspace or has an authenticator. */
    public function required(int $userId): bool
    {
        return DB::getDriverName() === 'pgsql' && DB::selectOne('SELECT app_account_requires_mfa(?) AS required', [$userId])->required;
    }

    /** True when this session completed the sign-in check for this account. */
    public function proven(Request $request): bool
    {
        $proof = $request->session()->get('account_mfa');

        return $request->user() !== null && is_array($proof) && (int) ($proof['user'] ?? 0) === (int) $request->user()->id;
    }

    public function prove(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->put('account_mfa', ['user' => (int) $request->user()->id, 'at' => time()]);
    }

    public function secret(): string
    {
        return implode('', array_map(fn () => self::ALPHABET[random_int(0, 31)], range(1, 32)));
    }

    /** Ten codes of ten characters, shown once. Only digests are stored. */
    public function recoveryCodes(): array
    {
        return array_map(fn () => implode('-', str_split(implode('', array_map(fn () => self::ALPHABET[random_int(0, 31)], range(1, 10))), 5)), range(1, 10));
    }

    /** The matching time step when the code is current and unused, otherwise null. The caller stores the step. */
    public function match(string $secret, ?int $lastCounter, string $code): ?int
    {
        foreach ([intdiv(time(), 30) - 1, intdiv(time(), 30), intdiv(time(), 30) + 1] as $counter) {
            if ($counter > ($lastCounter ?? -1) && hash_equals($this->code($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    /** Refuses further attempts after five failures in fifteen minutes, for codes and recovery codes alike. */
    public function guard(int $userId, string $field): void
    {
        if (RateLimiter::tooManyAttempts($this->key($userId), 5)) {
            throw ValidationException::withMessages([$field => 'Too many attempts. Wait '.(int) ceil(RateLimiter::availableIn($this->key($userId)) / 60).' minutes and try again.'])->status(429);
        }
    }

    public function failed(int $userId): void
    {
        RateLimiter::hit($this->key($userId), 900);
    }

    public function passed(int $userId): void
    {
        RateLimiter::clear($this->key($userId));
    }

    /** RFC 6238 SHA-1, six digits, thirty seconds. */
    public function code(string $secret, int $counter): string
    {
        $binary = '';
        foreach (str_split($secret) as $character) {
            $binary .= str_pad(decbin(strpos(self::ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
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

    private function key(int $userId): string
    {
        return 'account-mfa:'.$userId;
    }
}
