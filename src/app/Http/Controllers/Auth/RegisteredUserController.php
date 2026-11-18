<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\MailBudgetExceeded;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class RegisteredUserController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse|RedirectResponse
    {
        $user = User::create($request->validated());
        if (in_array($request->route('portal'), ['brand', 'partner', 'professional'], true)) {
            $portal = $request->route('portal');
            $request->session()->put('portal', $portal);
            $user->forceFill(['registration_workspace_id' => (string) Str::uuid(), 'registration_workspace_kind' => $portal === 'brand' ? 'brand' : 'creator', 'registration_workspace_experience' => $portal === 'professional' ? 'professional' : 'partner'])->save();
        }
        $emailPaused = false;
        try {
            event(new Registered($user));
        } catch (MailBudgetExceeded) {
            // The account already exists. Let its owner sign in and request verification after the pause.
            $emailPaused = true;
        }
        Auth::login($user);
        $request->session()->regenerate();

        return $this->completed($request, ['message' => $emailPaused
            ? 'Your account was created. Verification email is temporarily paused; request a link later.'
            : 'Check your email to verify your account', 'verification_email_paused' => $emailPaused], '/verify-email', 201);
    }
}
