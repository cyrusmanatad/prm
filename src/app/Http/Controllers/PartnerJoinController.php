<?php

namespace App\Http\Controllers;

use App\Services\PartnershipInvitation;
use App\Services\Workspaces;
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

        $workspace = $request->user()?->hasVerifiedEmail() ? collect(app(Workspaces::class)->listing((int) $request->user()->id))->first(fn ($row) => $row->id === $request->session()->get('active_org_id') && $row->kind === 'creator' && in_array($row->role, ['owner', 'admin'], true)) : null;

        return Inertia::render('JoinBrand', ['brand' => $join['brand'] ?? null, 'workspace' => $workspace ? ['name' => $workspace->name, 'experience' => $workspace->experience] : null, 'verified' => $request->user()?->hasVerifiedEmail() ?? false]);
    }

    public function select(Request $request, Workspaces $workspaces)
    {
        if (! PartnershipInvitation::join($request)) {
            return redirect('/join')->withErrors(['code' => 'Your sign-up session expired. Open the brand link or enter its code again.']);
        }
        $eligible = collect($workspaces->listing((int) $request->user()->id))->filter(fn ($row) => $row->kind === 'creator' && in_array($row->role, ['owner', 'admin'], true));
        $selected = $eligible->firstWhere('id', $request->session()->get('active_org_id'));
        $selected ??= $eligible->count() === 1 ? $eligible->first() : null;
        if (! $selected) {
            return redirect('/workspaces')->with('status', 'Select or create a Partner or Professional workspace you own or administer, then continue joining the brand.');
        }
        $request->session()->put('active_org_id', $selected->id);

        return redirect('/join');
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

        return redirect($request->user() && $request->user()->hasVerifiedEmail() ? '/portal/enter' : '/join');
    }

    public function accept(Request $request)
    {
        $request->validate(['consent' => ['accepted']]);
        $join = PartnershipInvitation::join($request);
        $ok = $join && DB::selectOne('SELECT app_join_partner(?::text,?::uuid) AS allowed', [hash('sha256', $join['code']), (string) Str::uuid()])->allowed;
        if (! $ok) {
            $message = $join
                ? 'This account cannot join. You may already be partnered with this brand, or the brand may have replaced its link.'
                : 'Your sign-up link expired. Open the brand link again to continue.';
            abort_if($request->expectsJson() && ! $request->header('X-Inertia'), 404, $message);

            return redirect('/join')->withErrors(['code' => $message]);
        }
        $request->session()->forget('partnership_join');

        return $this->completed($request, ['message' => 'Partnership with '.$join['brand'].' started'], DB::table('organizations')->where('id', $request->session()->get('active_org_id'))->value('experience') === 'professional' ? '/professional' : '/partner/leads');
    }

    public function issue(Request $request)
    {
        $data = $request->validate(['link_id' => ['present', 'nullable', 'uuid']]);
        $code = PartnershipInvitation::newCode();
        $ok = DB::selectOne('SELECT app_issue_join_link(?::uuid,?::text,?::text,?::uuid,?::uuid) AS allowed', [(string) Str::uuid(), hash('sha256', $code), encrypt($code), (string) Str::uuid(), $data['link_id']])->allowed;
        if (! $ok) {
            return $this->managementError($request);
        }

        return $this->completed($request, ['message' => 'Sign-up link ready', ...self::share($code)], '/brand');
    }

    public function disable(Request $request)
    {
        $data = $request->validate(['link_id' => ['required', 'uuid']]);
        $ok = DB::selectOne('SELECT app_disable_join_link(?::uuid,?::uuid) AS allowed', [(string) Str::uuid(), $data['link_id']])->allowed;
        if (! $ok) {
            return $this->managementError($request);
        }

        return $this->completed($request, ['message' => 'Sign-up link turned off'], '/brand');
    }

    private function managementError(Request $request)
    {
        $message = 'The sign-up link or your permissions changed. Cancel and reload before trying again.';
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message, 'errors' => ['version' => $message]], 409);
        }

        return back()->withErrors(['version' => $message]);
    }

    /** Shareable link and typed code for an active brand sign-up link. */
    public static function share(string $code): array
    {
        $display = PartnershipInvitation::displayCode($code);

        return ['code' => $display, 'url' => rtrim(config('app.url'), '/').'/join#'.$display];
    }
}
