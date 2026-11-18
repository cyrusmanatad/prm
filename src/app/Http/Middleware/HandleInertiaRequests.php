<?php

namespace App\Http\Middleware;

use App\Services\UploadSafety;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'platformAccess' => fn () => $this->platformAccess($request),
            'status' => fn () => $request->session()->get('status', ''),
        ];
    }

    /**
     * Presentation only: whether the account menu offers the administration link.
     * Administration authorizes every request itself; this never grants access.
     */
    private function platformAccess(Request $request): bool
    {
        $user = $request->user();
        if (! $user || ! $user->hasVerifiedEmail() || DB::getDriverName() !== 'pgsql') {
            return false;
        }
        try {
            // Row security lets an account read only its own grant.
            return (bool) app(TenantContext::class)->run((int) $user->id, null, fn () => DB::table('platform_grants')
                ->where('user_id', $user->id)->whereNull('revoked_at')->whereRaw('cardinality(permissions) > 0')->exists());
        } catch (\Throwable) {
            return false;
        }
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
