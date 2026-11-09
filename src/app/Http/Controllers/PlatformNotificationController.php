<?php

namespace App\Http\Controllers;

use App\Services\PlatformAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PlatformNotificationController extends Controller
{
    public function index(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['workspace' => ['nullable', 'uuid'], 'state' => ['nullable', Rule::in(['pending', 'sending', 'sent', 'captured', 'cancelled', 'uncertain'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $admin->run($request, function () use ($request, $data) {
            abort_unless(DB::selectOne("SELECT app_platform_allowed('notifications.read') AS ok")->ok, 403);
            $notices = json_decode(DB::selectOne('SELECT app_platform_notifications(?::uuid,?,?) AS data', [$data['workspace'] ?? null, $data['state'] ?? null, $data['page'] ?? 1])->data, true, flags: JSON_THROW_ON_ERROR);
            $payload = ['notices' => $notices, 'workspace' => $data['workspace'] ?? '', 'state' => $data['state'] ?? '', 'page' => (int) ($data['page'] ?? 1),
                'canResolve' => DB::selectOne("SELECT app_platform_allowed('notifications.reconcile') AS ok")->ok,
                'canDirectory' => DB::selectOne("SELECT app_platform_allowed('directory.read') AS ok")->ok];

            return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('PlatformNotifications', $payload);
        });
    }

    public function resolve(Request $request, PlatformAdministration $admin, string $notification): mixed
    {
        $data = $request->validate(['workspace' => ['nullable', 'uuid'], 'state' => ['nullable', Rule::in(['pending', 'sending', 'sent', 'captured', 'cancelled', 'uncertain'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'resolution' => ['required', Rule::in(['transport_accepted', 'not_sent', 'unknown_closed'])],
            'reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/']]);
        try {
            return $admin->run($request, function () use ($request, $data, $notification) {
                abort_unless(DB::selectOne("SELECT app_platform_allowed('notifications.reconcile') AS ok")->ok, 403);
                DB::select('SELECT app_platform_resolve_notification(?::uuid,?,?,?)', [$notification, $data['version'], $data['resolution'], trim($data['reason'])]);

                return $this->completed($request, ['message' => 'Resolution recorded; no email resent'], '/admin/notifications?'.http_build_query(array_intersect_key($data, array_flip(['workspace', 'state', 'page']))));
            });
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? '') === '40001') {
                throw ValidationException::withMessages(['version' => 'This delivery changed. Reload diagnostics before recording a resolution.'])->status(409);
            }
            abort_if(($error->errorInfo[0] ?? '') === 'P0002', 404);
            throw $error;
        }
    }
}
