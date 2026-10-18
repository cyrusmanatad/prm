<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function completed(Request $request, array $payload, string $destination, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload, $status);
        }

        return redirect($destination)->with('status', $payload['message'] ?? null);
    }
}
