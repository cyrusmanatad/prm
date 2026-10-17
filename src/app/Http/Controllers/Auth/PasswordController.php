<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Use the same response whether or not an account exists.
        return response()->json(['message' => 'If the account exists, a reset link has been requested.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'], 'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                });
                event(new PasswordReset($user));
            });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }
}
