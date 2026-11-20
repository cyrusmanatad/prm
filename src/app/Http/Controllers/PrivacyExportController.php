<?php

namespace App\Http\Controllers;

use App\Services\PrivacyExports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyExportController extends Controller
{
    public function index(Request $request, PrivacyExports $exports): JsonResponse|Response
    {
        $data = $exports->listing($request);

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($data) : Inertia::render('PrivacyExports', $data);
    }

    public function verify(Request $request, PrivacyExports $exports): JsonResponse|RedirectResponse
    {
        $exports->verify($request, $request->validate(['password' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:6']]));

        return $this->completed($request, ['message' => 'Identity verified for 15 minutes.'], '/account/exports');
    }

    public function store(Request $request, PrivacyExports $exports): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['scope' => ['required', 'in:account,workspace'], 'org_id' => ['required_if:scope,workspace', 'nullable', 'uuid'],
            'password' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:6']]);
        $exports->verify($request, $data);
        $id = $exports->create($request, $data['scope'] === 'workspace' ? $data['org_id'] : null);

        return $this->completed($request, ['id' => $id, 'message' => 'Your private export is ready. Download it within 24 hours.'], '/account/exports', 201);
    }

    public function download(Request $request, PrivacyExports $exports, string $export): \Illuminate\Http\Response
    {
        return response($exports->download($request, $export))->withHeaders([
            'Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="weavdash-export-'.$export.'.zip"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
