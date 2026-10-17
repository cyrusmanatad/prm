<?php

namespace App\Http\Controllers;

use App\Services\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request, Workspaces $workspaces): JsonResponse
    {
        return response()->json(['workspaces' => $workspaces->listing((int) $request->user()->id)]);
    }

    public function store(Request $request, Workspaces $workspaces): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $orgId = $workspaces->create((int) $request->user()->id, $data['name']);
        $request->session()->put('active_org_id', $orgId);
        $request->session()->regenerate();

        return response()->json(['id' => $orgId], 201);
    }

    public function select(Request $request, Workspaces $workspaces): JsonResponse
    {
        $data = $request->validate(['org_id' => ['required', 'uuid']]);
        $workspaces->select((int) $request->user()->id, $data['org_id']);
        $request->session()->put('active_org_id', $data['org_id']);
        $request->session()->regenerate();

        return response()->json(['message' => 'Workspace selected.']);
    }

    public function update(Request $request, Workspaces $workspaces): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $workspaces->rename((int) $request->user()->id, $request->session()->get('active_org_id'), $data['name']);

        return response()->json(['message' => 'Workspace updated.']);
    }
}
