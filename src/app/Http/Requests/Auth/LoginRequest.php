<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email'], 'password' => ['required', 'string']];
    }

    public function authenticate(): void
    {
        $key = Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($this));
            throw ValidationException::withMessages(['email' => __('auth.throttle', [
                'seconds' => RateLimiter::availableIn($key),
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ])]);
        }

        if (! Auth::attempt($this->only('email', 'password'), false)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Suspended identities cannot sign in. The message is the same whatever the reason for the suspension.
        if (DB::getDriverName() === 'pgsql' && DB::selectOne('SELECT app_account_suspended(?) AS suspended', [Auth::id()])->suspended) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'This account\'s access is suspended. Contact support.']);
        }

        RateLimiter::clear($key);
    }
}
