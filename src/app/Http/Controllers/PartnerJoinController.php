<?php

namespace App\Http\Controllers;

use App\Services\PartnershipInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PartnerJoinController extends Controller
{
    public function page(Request $request)
    {
        $join = PartnershipInvitation::join($request);
        if (! $join) {
            $request->session()->forget('partnership_join');
        }

        return Inertia::render('JoinBrand', ['brand' => $join['brand'] ?? null]);
    }

    public function prepare(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $code = PartnershipInvitation::normalizeCode($data['code']);
        $brand = $code ? DB::selectOne('SELECT app_join_link_brand(?::text) AS name', [hash('sha256', $code)])->name : null;
        if (! $brand) {
            $message = 'This sign-up link or code is not active. Check it with the brand.';
            abort_if($request->expectsJson() && ! $request->header('X-Inertia'), 404, $message);

            return back()->withErrors(['code' => $message]);
        }
        $request->session()->forget('partnership_token');
        $request->session()->put('partnership_join', ['encrypted' => encrypt($code), 'brand' => encrypt($brand), 'expires' => time() + 900]);
        $request->session()->put('portal', 'partner');
        // Guests who sign in to an existing account gain a new creator workspace; registration provisions its own.
        $request->session()->put('partnership_workspace', ! $request->user());

        return redirect('/join');
    }

    public function accept(Request $request)
    {
        $request->validate(['consent' => ['accepted']]);
        $join = PartnershipInvitation::join($request);
        $ok = $join && DB::selectOne('SELECT app_join_partner(?::text,?::uuid) AS allowed', [hash('sha256', $join['code']), (string) Str::uuid()])->allowed;
        if (! $ok) {
            $message = $join
                ? 'This workspace cannot join. Select a creator workspace you own or administer that is not already partnered with this brand, or ask the brand for its current link.'
                : 'Your sign-up link expired. Open the brand link again to continue.';
            abort_if($request->expectsJson() && ! $request->header('X-Inertia'), 404, $message);

            return back()->withErrors(['code' => $message]);
        }
        $request->session()->forget('partnership_join');

        return $this->completed($request, ['message' => 'Partnership with '.$join['brand'].' started'], '/partner/leads');
    }

    public function issue(Request $request)
    {
        $code = PartnershipInvitation::newCode();
        $ok = DB::selectOne('SELECT app_issue_join_link(?::uuid,?::text,?::text,?::uuid) AS allowed', [(string) Str::uuid(), hash('sha256', $code), encrypt($code), (string) Str::uuid()])->allowed;
        abort_unless($ok, 403);

        return $this->completed($request, ['message' => 'Sign-up link ready', ...self::share($code)], '/brand');
    }

    public function disable(Request $request)
    {
        $ok = DB::selectOne('SELECT app_disable_join_link(?::uuid) AS allowed', [(string) Str::uuid()])->allowed;
        abort_unless($ok, 404);

        return $this->completed($request, ['message' => 'Sign-up link turned off'], '/brand');
    }

    /** Shareable link and typed code for an active brand sign-up link. */
    public static function share(string $code): array
    {
        $display = PartnershipInvitation::displayCode($code);

        return ['code' => $display, 'url' => rtrim(config('app.url'), '/').'/join#'.$display];
    }
}
