<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SessionController extends Controller
{
    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $portal = $request->route('portal');
        $request->session()->forget('url.intended');
        if (in_array($portal, ['brand', 'partner'], true)) {
            $request->session()->put('portal', $portal);
        } else {
            $request->session()->forget('portal');
        }
        $request->authenticate();
        $request->session()->regenerate();

        return $this->completed($request, ['verified' => $request->user()->hasVerifiedEmail()],
            $request->user()->hasVerifiedEmail() ? (($portal || $request->user()->registration_workspace_id || $request->session()->has('partnership_token')) ? '/portal/enter' : '/workspaces') : '/verify-email');
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Inertia::clearHistory();

        return $this->completed($request, ['message' => 'Signed out.'], '/login');
    }
}
