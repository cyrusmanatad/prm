<?php

namespace App\Http\Middleware;

use App\Services\UploadSafety;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'stagingMode' => (bool) config('staging.enabled'),
            'uploadsEnabled' => (bool) config('uploads.enabled'),
            'unscannedUploadsAllowed' => UploadSafety::unscannedAllowed(),
            'account' => fn () => $request->user()?->only(['name', 'email']),
            'status' => fn () => $request->session()->get('status', ''),
        ];
    }

    public function handle(Request $request, \Closure $next): Response
    {
        Inertia::encryptHistory();
        $response = parent::handle($request, $next);
        // Authenticated props and reset tokens must not enter shared HTTP caches.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
