<?php

namespace App\Http\Controllers;

use App\Services\PlatformAdministration;
use App\Services\PrivacyClosures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PlatformPrivacyController extends Controller
{
    public function index(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $admin->run($request, function () use ($request, $data) {
            abort_unless(DB::selectOne("SELECT app_platform_allowed('platform.owner') AS allowed")->allowed, 403);
            $listing = json_decode(DB::selectOne('SELECT app_privacy_review_list(?) AS data', [$data['page'] ?? 1])->data, true, flags: JSON_THROW_ON_ERROR);
            $props = ['listing' => $listing, 'page' => (int) ($data['page'] ?? 1)];

            return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($props) : Inertia::render('PlatformPrivacy', $props);
        });
    }

    public function update(Request $request, PlatformAdministration $admin, PrivacyClosures $closures, string $closure): mixed
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:assign,review,cancel'],
            'reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/'], 'verification_reference' => ['required_if:action,cancel', 'nullable', 'string', 'min:8', 'max:160'],
            'password' => ['required', 'string', 'max:255'], 'code' => ['required', 'regex:/^[0-9]{6}$/D']]);

        return $closures->change(fn () => $admin->run($request, function () use ($request, $admin, $closure, $data) {
            $admin->confirm($request, $data['password'], $data['code']);
            if ($data['action'] === 'cancel') {
                DB::select('SELECT app_privacy_cancel(?::uuid,?,?,?)', [$closure, $data['version'], trim($data['reason']), trim($data['verification_reference'])]);
            } else {
                DB::select('SELECT app_privacy_review(?::uuid,?,?,?)', [$closure, $data['version'], $data['action'], trim($data['reason'])]);
            }

            return $this->completed($request, ['message' => 'Privacy request updated. No records were erased.'], '/admin/privacy-requests');
        }));
    }
}
