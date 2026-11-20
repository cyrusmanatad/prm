<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\MailBudgetExceeded;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function sendLink(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        try {
            $account = User::where('email', $request->input('email'))->first();
            if ($account && DB::getDriverName() === 'pgsql') {
                DB::transaction(function () use ($account, $request): void {
                    if (DB::selectOne('SELECT app_privacy_mail_allowed(?) AS allowed', [$account->id])->allowed) {
                        Password::sendResetLink($request->only('email'));
                    }
                });
            } else {
                Password::sendResetLink($request->only('email'));
            }
        } catch (MailBudgetExceeded) {
            // Keep the same recovery response for existing and unknown accounts, even at the cap.
            // "Requested" does not claim transport acceptance or receipt; budget monitoring reports the pause.
        }

        // Use the same response whether or not an account exists.
        return $this->completed($request, ['message' => 'If the account exists, a reset link has been requested.'], '/forgot-password');
    }

    public function reset(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'], 'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    if (DB::getDriverName() === 'pgsql' && ! DB::selectOne('SELECT app_privacy_mail_allowed(?) AS allowed', [$user->id])->allowed) {
                        throw ValidationException::withMessages(['email' => 'This reset link is unavailable. Contact support.']);
                    }
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                });
                event(new PasswordReset($user));
            });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return $this->completed($request, ['message' => __($status)], '/login');
    }
}
