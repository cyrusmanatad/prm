<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class Controller
{
    protected function completed(Request $request, array $payload, string $destination, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload, $status);
        }

        return redirect($destination)->with('status', $payload['message'] ?? null);
    }

    /**
     * Return to the automation page the request came from, keeping its tab and open draft.
     * Only the two automation addresses and their known query values are kept; anything else returns to /automation.
     */
    protected function automationReturn(Request $request, array $overrides = []): string
    {
        $previous = parse_url(url()->previous());
        $fromPage = in_array($previous['path'] ?? '', ['/automation', '/professional/automation'], true);
        $path = $fromPage ? $previous['path'] : '/automation';
        parse_str($fromPage ? ($previous['query'] ?? '') : '', $query);
        $keep = array_filter([
            'tab' => in_array($query['tab'] ?? null, ['schedules', 'history', 'preferences'], true) ? $query['tab'] : null,
            'draft' => is_string($query['draft'] ?? null) && Str::isUuid($query['draft']) ? $query['draft'] : null,
        ]);
        $query = array_filter([...$keep, ...$overrides], fn ($value) => $value !== null);

        return $path.($query ? '?'.http_build_query($query) : '');
    }
}
