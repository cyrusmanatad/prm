<?php

namespace App\Http\Controllers;

use App\Services\PrivacyClosures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PrivacyClosureController extends Controller
{
    public function index(Request $request, PrivacyClosures $closures): mixed
    {
        $data = $request->validate(['scope' => ['sometimes', 'in:account,workspace'], 'org_id' => ['nullable', 'uuid']]);
        $props = $closures->change(fn () => $closures->page($request, $data['scope'] ?? 'account', ($data['scope'] ?? 'account') === 'workspace' ? ($data['org_id'] ?? null) : null));

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($props) : Inertia::render('PrivacyClosures', $props);
    }

    public function store(Request $request, PrivacyClosures $closures): mixed
    {
        $data = $request->validate(['scope' => ['required', 'in:account,workspace'], 'org_id' => ['required_if:scope,workspace', 'nullable', 'uuid'],
            'manifest_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/'],
            'password' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:6']]);
        $id = $closures->close($request, $data);
        if ($data['scope'] === 'account') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Inertia::clearHistory();
        }

        return $this->completed($request, ['id' => $id, 'message' => 'Closure requested. Access is restricted and records are retained for privacy review.'], $data['scope'] === 'account' ? '/login' : '/account/closure', 201);
    }

    public function cancel(Request $request, PrivacyClosures $closures, string $closure): mixed
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/'],
            'password' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:6']]);
        $closures->cancel($request, $closure, $data);

        return $this->completed($request, ['message' => 'Closure cancelled. Eligible access is available; links, partnerships and cancelled work remain ended.'], '/account/closure');
    }
}
