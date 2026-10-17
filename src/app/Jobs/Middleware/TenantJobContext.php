<?php

namespace App\Jobs\Middleware;

use App\Tenancy\TenantContext;
use Closure;

/** Jobs carry scalar actorId/orgId only, never tenant Eloquent models. */
class TenantJobContext
{
    public function handle(object $job, Closure $next): mixed
    {
        return app(TenantContext::class)->run($job->actorId, $job->orgId, fn () => $next($job));
    }
}
