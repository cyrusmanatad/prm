<?php

namespace App\Http\Controllers;

use App\Services\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request, Workspaces $workspaces): JsonResponse|Response
    {
        $listing = $workspaces->listing((int) $request->user()->id);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['workspaces' => $listing]);
        }
        $selected = $request->session()->get('active_org_id');
        $revoked = $selected !== null && ! in_array($selected, array_column($listing, 'id'), true);
        if ($revoked) {
            $request->session()->forget('active_org_id');
        }

        // Materialized allowlisted data only; no lazy tenant queries after context closes.
        return Inertia::render('Workspaces', [
            'workspaces' => $listing, 'activeOrgId' => $revoked ? null : $selected,
            'accessRevoked' => $revoked,
        ]);
    }

    public function store(Request $request, Workspaces $workspaces): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'kind' => ['sometimes', 'in:creator,brand']]);
        $orgId = $workspaces->create((int) $request->user()->id, $data['name'], $data['kind'] ?? 'creator');
        $request->session()->put('active_org_id', $orgId);
        $request->session()->regenerate();

        return $this->completed($request, ['id' => $orgId, 'message' => 'Workspace created.'], '/workspaces', 201);
    }

    public function select(Request $request, Workspaces $workspaces): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['org_id' => ['required', 'uuid']]);
        $workspaces->select((int) $request->user()->id, $data['org_id']);
        $request->session()->forget('portal');
        $request->session()->put('active_org_id', $data['org_id']);
        $request->session()->regenerate();

        return $this->completed($request, ['message' => 'Workspace selected.'], $request->session()->has('partnership_token') ? '/partnerships/accept' : '/workspaces');
    }

    public function update(Request $request, Workspaces $workspaces): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $workspaces->rename((int) $request->user()->id, $request->session()->get('active_org_id'), $data['name']);

        return $this->completed($request, ['message' => 'Workspace updated.'], '/workspaces');
    }
}
