<?php

namespace App\Http\Controllers;

use App\Services\PlatformAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PlatformController extends Controller
{
    public function security(Request $request, PlatformAdministration $admin): mixed
    {
        return $admin->run($request, function () use ($request) {
            return Inertia::render('PlatformSecurity', ['enrolled' => $request->user()->admin_totp_confirmed_at !== null]);
        }, false);
    }

    public function enroll(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        return $admin->run($request, function () use ($request, $data) {
            abort_unless(Hash::check($data['password'], $request->user()->password), 403);
            abort_if($request->user()->admin_totp_confirmed_at, 409);
            $secret = implode('', array_map(fn () => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[random_int(0, 31)], range(1, 32)));
            $request->session()->put('platform_enrollment', ['secret' => encrypt($secret), 'until' => time() + 600]);

            return Inertia::render('PlatformSecurity', ['enrolled' => false, 'secret' => $secret]);
        }, false);
    }

    public function verify(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'regex:/^[0-9]{6}$/D']]);

        return $admin->run($request, function ($grant) use ($request, $data, $admin) {
            $user = DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            abort_unless(Hash::check($data['password'], $user->password), 403);
            $pending = $request->session()->get('platform_enrollment');
            $encrypted = $user->admin_totp_secret ?? (is_array($pending) && $pending['until'] > time() ? $pending['secret'] : null);
            abort_unless($encrypted, 403);
            $secret = decrypt($encrypted);
            $matched = null;
            foreach ([intdiv(time(), 30) - 1, intdiv(time(), 30), intdiv(time(), 30) + 1] as $counter) {
                if ($counter > ($user->admin_totp_counter ?? -1) && hash_equals($admin->code($secret, $counter), $data['code'])) {
                    $matched = $counter;
                    break;
                }
            }
            if ($matched === null) {
                throw ValidationException::withMessages(['code' => 'Enter a fresh authenticator code.']);
            }
            DB::table('users')->where('id', $user->id)->update(['admin_totp_secret' => $encrypted, 'admin_totp_confirmed_at' => $user->admin_totp_confirmed_at ?? now(), 'admin_totp_counter' => $matched]);
            DB::select('SELECT app_platform_audit(?,?,?)', [$user->admin_totp_confirmed_at ? 'mfa.verified' : 'mfa.enrolled', (string) $user->id, 'Password and authenticator verified']);
            $request->session()->forget('platform_enrollment');
            $request->session()->regenerate();
            $request->session()->put('platform_mfa', ['user' => $user->id, 'version' => $grant->version, 'until' => time() + 900]);

            DB::select("SELECT set_config('app.admin_version',?,true)", [(string) $grant->version]);

            return redirect(DB::selectOne("SELECT app_platform_allowed('directory.read') AS ok")->ok ? '/admin' : '/admin/notifications');
        }, false);
    }

    public function index(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $admin->run($request, function () use ($request, $data) {
            abort_unless(DB::selectOne("SELECT app_platform_allowed('directory.read') AS ok")->ok, 403);
            $directory = json_decode(DB::selectOne('SELECT app_platform_directory(?,?) AS data', [$data['q'] ?? '', $data['page'] ?? 1])->data, true);
            $payload = [...$directory, 'q' => $data['q'] ?? '', 'page' => (int) ($data['page'] ?? 1), 'canNotifications' => DB::selectOne("SELECT app_platform_allowed('notifications.read') AS ok")->ok, 'canAssign' => DB::selectOne("SELECT app_platform_allowed('plans.assign') AS ok")->ok];

            return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('PlatformDirectory', $payload);
        });
    }

    public function assign(Request $request, PlatformAdministration $admin): mixed
    {
        $resources = ['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'];
        $rules = ['workspace' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'], 'name' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:240'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'quotas' => ['required', 'array:'.implode(',', $resources)]];
        foreach ($resources as $resource) {
            $rules['quotas.'.$resource] = ['required', 'integer', 'min:0', 'max:999999999'];
        }
        $data = $request->validate($rules);
        try {
            return $admin->run($request, function () use ($request, $data) {
                abort_unless(DB::selectOne("SELECT app_platform_allowed('plans.assign') AS ok")->ok, 403);
                $version = DB::selectOne('SELECT app_platform_assign(?::uuid,?::integer,?,?::jsonb,?::timestamptz,?::timestamptz,?,?::uuid,?::uuid) AS version', [
                    $data['workspace'], $data['expected_version'], trim($data['name']), json_encode(array_map(intval(...), $data['quotas']), JSON_THROW_ON_ERROR), $data['starts_at'], $data['ends_at'], trim($data['reason']), (string) Str::uuid(), (string) Str::uuid(),
                ])->version;

                return $this->completed($request, ['version' => $version, 'message' => 'Subscription assignment recorded'], '/admin');
            });
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) !== '40001') {
                throw $error;
            }
            throw ValidationException::withMessages(['expected_version' => 'Assignment changed. Reload the directory before saving again.'])->status(409);
        }
    }
}
