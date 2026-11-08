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
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $orgId = $request->session()->get('active_org_id');
        abort_unless(is_string($orgId), 409, 'Select a workspace first.');

        return $this->context->run((int) $request->user()->id, $orgId, function () use ($request, $next) {

            $professional = DB::table('organizations')->where('id', $request->session()->get('active_org_id'))->value('experience') === 'professional';
            Inertia::share('workspaceExperience', $professional ? 'professional' : 'partner');
            Inertia::share('professionalSubscription', $professional ? app(PilotEntitlements::class)->state($request->session()->get('active_org_id')) : null);
            if ($request->is('professional/*') && ! $professional) {
                abort(404);
            }
            // Materialize responses before leaving the transaction; no streamed
            // or deferred tenant queries may run after this middleware returns.
            $response = $next($request);
            abort_if($response instanceof StreamedResponse, 500);

            if ($professional && $response->isRedirection()) {
                $location = $response->headers->get('Location');
                $response->headers->set('Location', str_replace(['/partner/media-kit', '/partner/'], ['/professional/profile', '/professional/'], $location));
            }

            return $response;
        });
    }
}
