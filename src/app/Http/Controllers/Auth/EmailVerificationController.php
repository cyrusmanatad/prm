<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(EmailVerificationRequest $request): JsonResponse|RedirectResponse
    {
        $request->fulfill();

        return $this->completed($request, ['message' => 'Email verified'], ($request->session()->has('portal') || $request->user()->registration_workspace_id || $request->session()->has('partnership_token')) ? '/portal/enter' : '/workspaces');
    }

    public function send(Request $request): JsonResponse|RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return $this->completed($request, ['message' => 'Verification link requested'], '/verify-email');
    }
}
