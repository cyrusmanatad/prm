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
            'uploadsEnabled' => (bool) config('uploads.enabled'),
            'unscannedUploadsAllowed' => UploadSafety::unscannedAllowed(),
            'account' => fn () => $request->user()?->only(['name', 'email']),
            'platformAccess' => fn () => $this->platformAccess($request),
            'activeWorkspace' => fn () => $this->activeWorkspace($request),
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
            return (bool) $this->asActor((int) $user->id, fn () => DB::table('platform_grants')
                ->where('user_id', $user->id)->whereNull('revoked_at')->whereRaw('cardinality(permissions) > 0')->exists());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Presentation only: the selected workspace's kind and experience, so the account menu offers the same
     * workspace tools on every page. Null without a current membership. Routes authorize themselves.
     *
     * @return array{kind: string, experience: string}|null
     */
    private function activeWorkspace(Request $request): ?array
    {
        $user = $request->user();
        $orgId = $request->hasSession() ? $request->session()->get('active_org_id') : null;
        if (! $user || ! is_string($orgId) || ! preg_match('/^[0-9a-f-]{36}$/', $orgId) || DB::getDriverName() !== 'pgsql') {
            return null;
        }
        try {
            // The same membership projection as the workspace list; row security limits it to this account.
            $row = $this->asActor((int) $user->id, fn () => DB::table('organizations')
                ->join('memberships', 'memberships.org_id', '=', 'organizations.id')
                ->where('memberships.user_id', $user->id)->whereNull('memberships.revoked_at')
                ->where('organizations.id', $orgId)->first(['organizations.kind', 'organizations.experience']));
        } catch (\Throwable) {
            return null;
        }

        return $row ? ['kind' => (string) $row->kind, 'experience' => (string) ($row->experience ?? 'partner')] : null;
    }

    /**
     * Run a read as the signed-in account. Workspace routes already hold the tenant context (and its outermost
     * transaction) for this account while the page renders, so the read joins it instead of opening another.
     */
    private function asActor(int $actorId, \Closure $read): mixed
    {
        if (DB::connection()->transactionLevel() > 0) {
            $actor = DB::selectOne('SELECT app_actor() AS actor')->actor;
            if ((int) $actor !== $actorId) {
                throw new \LogicException('The open tenant context belongs to another account.');
            }

            // A savepoint keeps a failed read from aborting the page's transaction.
            return DB::transaction($read);
        }

        return app(TenantContext::class)->run($actorId, null, $read);
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
