<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['message' => 'Check your email to verify your account.'], 201);
    }
}
