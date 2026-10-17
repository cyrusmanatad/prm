<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
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
            // Materialize responses before leaving the transaction; no streamed
            // or deferred tenant queries may run after this middleware returns.
            $response = $next($request);
            abort_if($response instanceof StreamedResponse, 500);

            return $response;
        });
    }
}
