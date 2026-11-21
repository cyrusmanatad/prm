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
        return $this->returnTo($request, ['/automation', '/professional/automation'], ['tab' => ['schedules', 'history', 'preferences'], 'draft' => 'uuid'], $overrides);
    }

    /**
     * The page address a form came from, when it is one of `$paths`, with only the allowed query values: each key
     * maps to its permitted values, or to 'uuid'. Any other referer returns to the first path. `$overrides` set or
     * (with null) remove values.
     */
    protected function returnTo(Request $request, array $paths, array $allowed, array $overrides = []): string
    {
        $previous = parse_url(url()->previous());
        $fromPage = in_array($previous['path'] ?? '', $paths, true);
        parse_str($fromPage ? ($previous['query'] ?? '') : '', $query);
        $keep = [];
        foreach ($allowed as $key => $values) {
            $value = $query[$key] ?? null;
            if (is_string($value) && ($values === 'uuid' ? Str::isUuid($value) : in_array($value, $values, true))) {
                $keep[$key] = $value;
            }
        }
        $query = array_filter([...$keep, ...$overrides], fn ($value) => $value !== null);

        return ($fromPage ? $previous['path'] : $paths[0]).($query ? '?'.http_build_query($query) : '');
    }
}
