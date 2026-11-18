<?php

namespace App\Http\Middleware;

use App\Services\PilotEntitlements;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceContext
{
    public const SUSPENDED = 'This workspace\'s access is suspended. Its records are kept. Contact support.';

    /** Session marker left by a completed password sign-in until its first workspace is entered. */
    public const SIGN_IN = 'workspace_sign_in';

    /** A sign-in that enters no workspace within this many seconds is not credited to one. */
    private const SIGN_IN_WINDOW = 3600;

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $orgId = $request->session()->get('active_org_id');
        abort_unless(is_string($orgId), 409, 'Select a workspace first.');
        abort_if(preg_match('/^[0-9a-f-]{36}$/', $orgId) && DB::selectOne('SELECT app_workspace_suspended(?::uuid) AS suspended', [$orgId])->suspended, 403, self::SUSPENDED);

        return $this->context->run((int) $request->user()->id, $orgId, function () use ($request, $next) {

            $professional = DB::table('organizations')->where('id', $request->session()->get('active_org_id'))->value('experience') === 'professional';
            Inertia::share('workspaceExperience', $professional ? 'professional' : 'partner');
            Inertia::share('professionalSubscription', $professional ? app(PilotEntitlements::class)->state($request->session()->get('active_org_id')) : null);
            if ($request->is('professional/*') && ! $professional) {
                abort(404);
            }
            // This middleware runs after the account-suspension, verification and owner-MFA checks, so only a
            // completed sign-in reaches it. The database records nothing for Brand workspaces.
            $signIn = $request->session()->get(self::SIGN_IN);
            if (is_array($signIn) && (int) ($signIn['user'] ?? 0) === (int) $request->user()->id && time() - (int) ($signIn['at'] ?? 0) <= self::SIGN_IN_WINDOW) {
                DB::selectOne('SELECT app_record_workspace_sign_in() AS recorded');
            }
            // Materialize responses before leaving the transaction; no streamed
            // or deferred tenant queries may run after this middleware returns.
            $response = $next($request);
            abort_if($response instanceof StreamedResponse, 500);
            // One workspace per sign-in. A request that fails keeps the marker, because its transaction rolls back.
            $request->session()->forget(self::SIGN_IN);

            if ($professional && $response->isRedirection()) {
                $location = $response->headers->get('Location');
                $response->headers->set('Location', str_replace(['/partner/media-kit', '/partner/'], ['/professional/profile', '/professional/'], $location));
            }

            return $response;
        });
    }
}
