<?php

namespace App\Http\Middleware;

use App\Services\Monitoring;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * M6-03. Gives each request a server-generated correlation ID (shared with its log lines and queued jobs),
 * counts finished requests for the monitor and logs server errors and slow requests.
 * Logs carry the route pattern, never the URL: public capability links hold tokens in their path.
 */
class RequestTelemetry
{
    public function __construct(private Monitoring $monitoring) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        Context::add('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        // The liveness probe would dilute the counts and says nothing about user requests.
        if (! config('monitoring.http.enabled') || $request->is('up')) {
            return;
        }
        $status = $response->getStatusCode();
        $milliseconds = (microtime(true) - (float) $request->server('REQUEST_TIME_FLOAT', microtime(true))) * 1000;
        $this->monitoring->countRequest($status, $milliseconds);
        if ($status >= 500 || $milliseconds >= config('monitoring.http.slow_ms')) {
            Log::warning('monitor.request', ['method' => $request->method(), 'route' => $request->route()?->uri() ?? 'unmatched', 'status' => $status, 'ms' => (int) $milliseconds]);
        }
    }
}
