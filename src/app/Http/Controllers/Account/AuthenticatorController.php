<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Authenticator;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Authenticator setup, the sign-in check and recovery codes for any account (M1-04). */
class AuthenticatorController extends Controller
{
    public function __construct(private Authenticator $authenticator, private TenantContext $context) {}

    public function show(Request $request): JsonResponse|Response
    {
        return $this->page($request);
    }

    /** Password generates a setup key held in the session for ten minutes. Replacing needs a verified session. */
    public function enroll(Request $request): JsonResponse|RedirectResponse|Response
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $this->password($request, $data['password']);
        abort_if($request->user()->admin_totp_confirmed_at !== null && ! $this->authenticator->proven($request), 403);
        $secret = $this->authenticator->secret();
        $request->session()->put('account_enrollment', ['secret' => encrypt($secret), 'until' => time() + 600]);

        return $this->shown($request);
    }

    /** Abandons a pending setup key. The current authenticator, if any, is unchanged. */
    public function cancel(Request $request): JsonResponse|RedirectResponse|Response
    {
        $request->session()->forget('account_enrollment');

        return $this->shown($request);
    }

    /** Confirms a pending setup key, or checks the code of an enrolled account at sign-in. */
    public function verify(Request $request): JsonResponse|RedirectResponse|Response
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        $id = (int) $request->user()->id;
        $this->authenticator->guard($id, 'code');
        $pending = $request->session()->get('account_enrollment');
        $proven = $this->authenticator->proven($request);
        $codes = $this->context->run($id, null, function () use ($id, $data, $pending, $proven) {
            $user = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            $enrolled = $user->admin_totp_confirmed_at !== null;
            $setup = is_array($pending) && ($pending['until'] ?? 0) > time() && (! $enrolled || $proven);
            if (! $setup && ! $enrolled) {
                throw ValidationException::withMessages(['code' => 'Your setup key expired. Generate a new one.']);
            }
            $counter = $this->authenticator->match(decrypt($setup ? $pending['secret'] : $user->admin_totp_secret), $setup ? null : $user->admin_totp_counter, $data['code']);
            if ($counter === null) {
                return null;
            }
            if (! $setup) {
                DB::table('users')->where('id', $id)->update(['admin_totp_counter' => $counter]);

                return [];
            }
            DB::table('users')->where('id', $id)->update(['admin_totp_secret' => $pending['secret'], 'admin_totp_confirmed_at' => now(), 'admin_totp_counter' => $counter]);
            $operator = json_decode(DB::selectOne('SELECT app_account_mfa_state() AS data')->data, true)['operator'];
            $codes = $operator ? [] : $this->authenticator->recoveryCodes();
            DB::select('SELECT app_account_set_recovery_codes(?::text[])', ['{'.implode(',', $codes).'}']);
            DB::select('SELECT app_account_security_event(?)', [$enrolled ? 'authenticator.replaced' : 'authenticator.enrolled']);

            return ['setup' => true, 'codes' => $codes];
        });
        if ($codes === null) {
            $this->authenticator->failed($id);
            throw ValidationException::withMessages(['code' => 'Enter a fresh authenticator code.']);
        }
        $this->authenticator->passed($id);
        $request->session()->forget('account_enrollment');
        $this->authenticator->prove($request);
        if (! ($codes['setup'] ?? false)) {
            return $this->completed($request, ['message' => 'Authenticator verified'], $this->intended($request));
        }
        // A new authenticator ends every other session of the account.
        DB::table('sessions')->where('user_id', $id)->where('id', '<>', $request->session()->getId())->delete();

        return $this->shown($request, $codes['codes'], 'Authenticator set up');
    }

    /** Signs in with a single-use recovery code instead of the authenticator. */
    public function recover(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['recovery_code' => ['required', 'string', 'max:40']]);
        $id = (int) $request->user()->id;
        $this->authenticator->guard($id, 'recovery_code');
        if (! $this->context->run($id, null, fn () => DB::selectOne('SELECT app_account_use_recovery_code(?) AS used', [$data['recovery_code']])->used)) {
            $this->authenticator->failed($id);
            throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not valid or was already used.']);
        }
        $this->authenticator->passed($id);
        $this->authenticator->prove($request);

        return $this->completed($request, ['message' => 'Recovery code accepted. Replace your authenticator if you no longer have it.'], '/account/authenticator');
    }

    /** Replaces every recovery code. Needs a verified session, the password and a fresh code. */
    public function regenerate(Request $request): JsonResponse|RedirectResponse|Response
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        abort_unless($this->authenticator->proven($request), 403);
        $this->password($request, $data['password']);
        $id = (int) $request->user()->id;
        $this->authenticator->guard($id, 'code');
        $codes = $this->context->run($id, null, function () use ($id, $data) {
            $user = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            abort_unless($user->admin_totp_confirmed_at !== null && ! json_decode(DB::selectOne('SELECT app_account_mfa_state() AS data')->data, true)['operator'], 403);
            $counter = $this->authenticator->match(decrypt($user->admin_totp_secret), $user->admin_totp_counter, $data['code']);
            if ($counter === null) {
                return null;
            }
            $codes = $this->authenticator->recoveryCodes();
            DB::table('users')->where('id', $id)->update(['admin_totp_counter' => $counter]);
            DB::select('SELECT app_account_set_recovery_codes(?::text[])', ['{'.implode(',', $codes).'}']);
            DB::select('SELECT app_account_security_event(?)', ['recovery_codes.regenerated']);

            return $codes;
        });
        if ($codes === null) {
            $this->authenticator->failed($id);
            throw ValidationException::withMessages(['code' => 'Enter a fresh authenticator code.']);
        }
        $this->authenticator->passed($id);

        return $this->shown($request, $codes, 'New recovery codes created');
    }

    private function password(Request $request, string $password): void
    {
        if (! Hash::check($password, $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Enter your current password.']);
        }
    }

    /** Pages redirect to the authenticator page so a reload never repeats a change; new codes are flashed for one view. */
    private function shown(Request $request, ?array $codes = null, ?string $message = null): JsonResponse|RedirectResponse|Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return $this->page($request, $codes === null ? [] : ['recoveryCodes' => $codes, 'message' => $message]);
        }
        $redirect = redirect('/account/authenticator')->with('status', $message);

        return $codes === null ? $redirect : $redirect->with('account_recovery_codes', encrypt(json_encode($codes, JSON_THROW_ON_ERROR)));
    }

    private function page(Request $request, array $extra = []): JsonResponse|Response
    {
        $id = (int) $request->user()->id;
        $state = $this->context->run($id, null, fn () => json_decode(DB::selectOne('SELECT app_account_mfa_state() AS data')->data, true, flags: JSON_THROW_ON_ERROR));
        $verified = $state['enrolled'] && $this->authenticator->proven($request);
        $intended = $request->session()->get('account_mfa_intended');
        $pending = $request->session()->get('account_enrollment');
        $flashed = $request->session()->get('account_recovery_codes');
        $props = [
            'enrolled' => $state['enrolled'], 'verified' => $verified, 'required' => $state['owner'] || $state['enrolled'], 'operator' => $state['operator'],
            'recoveryRemaining' => $verified && ! $state['operator'] ? $state['recovery_remaining'] : null,
            'continueTo' => $verified ? ($this->safe($intended) ?? '/workspaces') : null,
            // The setup key stays visible while it is pending; recovery codes are shown for one view only.
            'secret' => is_array($pending) && ($pending['until'] ?? 0) > time() && (! $state['enrolled'] || $verified) ? decrypt($pending['secret']) : null,
            'recoveryCodes' => is_string($flashed) ? json_decode(decrypt($flashed), true, flags: JSON_THROW_ON_ERROR) : null,
            ...$extra,
        ];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($props) : Inertia::render('AccountAuthenticator', $props);
    }

    /** Destination recorded when the check interrupted a page visit. */
    private function intended(Request $request): string
    {
        return $this->safe($request->session()->pull('account_mfa_intended')) ?? '/workspaces';
    }

    private function safe(mixed $path): ?string
    {
        return is_string($path) && preg_match('#^/(?![/\\\\])#', $path) && ! str_starts_with($path, '/account/authenticator') ? $path : null;
    }
}
