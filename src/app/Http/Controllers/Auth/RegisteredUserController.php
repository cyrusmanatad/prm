<?php

namespace App\Http\Controllers\Auth;

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
        if (in_array($request->route('portal'), ['brand', 'partner'], true)) {
            $portal = $request->route('portal');
            $request->session()->put('portal', $portal);
            $user->forceFill(['registration_workspace_id' => (string) Str::uuid(), 'registration_workspace_kind' => $portal === 'brand' ? 'brand' : 'creator'])->save();
        }
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return $this->completed($request, ['message' => 'Check your email to verify your account.'], '/verify-email', 201);
    }
}
