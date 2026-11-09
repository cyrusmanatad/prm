<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class UploadRestrictions
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('uploads.enabled') && $request->isMethod('post') && $request->is('onboarding/form/*/documents')) {
            throw ValidationException::withMessages(['file' => 'Attachments are disabled in this staging. Continue without files.']);
        }

        return $next($request);
    }
}
