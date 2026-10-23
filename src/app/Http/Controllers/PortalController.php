<?php

namespace App\Http\Controllers;

use App\Http\Queries\PipelinePage;
use App\Services\NotificationOutbox;
use App\Services\Pipeline;
use App\Services\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PortalController extends Controller
{
    public function enter(Request $request, Workspaces $workspaces)
    {
        $user = $request->user();
        if ($user->registration_workspace_id) {
            $id = $workspaces->create((int) $user->id, $user->name, $user->registration_workspace_kind, $user->registration_workspace_id);
            $request->session()->put('active_org_id', $id);
            $user->forceFill(['registration_workspace_id' => null, 'registration_workspace_kind' => null])->save();
        }
        $listing = $workspaces->listing((int) $request->user()->id);
        $preference = $request->session()->get('portal');
        $selected = collect($listing)->first(fn ($row) => $row->id === $request->session()->get('active_org_id') && (! $preference || $row->kind === ($preference === 'brand' ? 'brand' : 'creator')));
        if (! $selected) {
            $matching = collect($listing)->filter(fn ($row) => ! $preference || $row->kind === ($preference === 'brand' ? 'brand' : 'creator'));
            $selected = $matching->count() === 1 ? $matching->first() : null;
        }
        if (! $selected) {
            return redirect('/workspaces');
        }
        $request->session()->put('active_org_id', $selected->id);
        $request->session()->regenerate();

        return redirect($request->session()->has('partnership_token') && $selected->kind === 'creator' ? '/partnerships/accept' : ($selected->kind === 'brand' ? '/brand' : '/partner/leads'));
    }

    public function acceptancePage(Request $request)
    {
        $token = $request->session()->get('partnership_token');
        $valid = is_array($token) && $token['expires'] > time();
        if (! $valid) {
            $request->session()->forget('partnership_token');
        }

        return Inertia::render('AcceptPartnership', ['savedToken' => $valid ? decrypt($token['encrypted']) : '']);
    }

    public function prepare(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'choose' => ['sometimes', 'boolean']]);
        $request->session()->put('partnership_token', ['encrypted' => encrypt($data['token']), 'expires' => time() + 900]);
        $request->session()->put('portal', 'partner');

        return redirect($request->user() ? ($request->user()->hasVerifiedEmail() ? (! empty($data['choose']) ? '/workspaces' : '/portal/enter') : '/verify-email') : '/partner/login');
    }

    public function index(Request $request, Pipeline $pipeline)
    {
        $org = $request->session()->get('active_org_id');
        $workspace = DB::table('organizations')->where('id', $org)->first(['id', 'name', 'kind']);
        $portal = $request->is('brand') ? 'brand' : 'partner';
        if (! $workspace || $workspace->kind !== ($portal === 'brand' ? 'brand' : 'creator')) {
            return redirect('/workspaces');
        }
        if ($request->is('partner') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect('/partner/leads'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }
        $relationships = DB::table('partnerships')->orderBy('accepted_at')->get(['id', 'brand_name', 'creator_name', 'status', 'accepted_at'])->all();
        $data = ['portal' => $portal, 'workspace' => $workspace, 'relationships' => $relationships,
            'canManage' => (bool) DB::selectOne("SELECT app_has_membership(?::uuid, ARRAY['owner','admin']) AS allowed", [$org])->allowed,
            'invitations' => DB::table('partner_invitations as i')->leftJoin('notification_outbox as n', 'n.subject_id', '=', 'i.id')
                ->orderByDesc('i.created_at')->limit(100)->get(['i.id', 'i.status', 'i.expires_at', 'n.state as email_state'])->all(),
            'pipeline' => $portal === 'partner' ? app(PipelinePage::class)->data($request, $pipeline) : null];
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($data);
        }

        return Inertia::render('Portal', [...$data, 'inviteUrl' => $request->session()->has('partner_invite_url') ? decrypt($request->session()->pull('partner_invite_url')) : null]);
    }

    public function invite(Request $request, NotificationOutbox $outbox)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], 'send_email' => ['sometimes', 'boolean']]);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        $ok = DB::selectOne('SELECT app_invite_partner(?::uuid,?::text,?::text) AS allowed', [$id, $data['email'], hash('sha256', $token)])->allowed;
        abort_unless($ok, 409, 'Invitation unavailable. Check your role and outstanding invitations.');
        $url = rtrim(config('app.url'), '/').'/partnerships/accept#'.$token;
        if (! empty($data['send_email'])) {
            $outbox->invitation((int) $request->user()->id, $request->session()->get('active_org_id'), $id, $url);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => $id, 'url' => $url], 201);
        }

        // Encrypted at rest in the session; consumed on the next browser portal render.
        return redirect('/brand')->with('partner_invite_url', encrypt($url));
    }

    public function accept(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'consent' => ['accepted']]);
        $ok = DB::selectOne('SELECT app_accept_partner(?::text,?::uuid) AS allowed', [hash('sha256', $data['token']), (string) Str::uuid()])->allowed;
        abort_unless($ok, 404, 'Invitation unavailable. Check your recipient account, creator workspace and invitation expiry.');

        $request->session()->forget('partnership_token');

        return $this->completed($request, ['message' => 'Partnership accepted.'], '/partner/leads');
    }

    public function revoke(Request $request, string $record)
    {
        $ok = DB::selectOne('SELECT app_revoke_partner(?::uuid,?::uuid,?::boolean) AS allowed', [$record, (string) Str::uuid(), $request->is('partner-invitations/*') ? 'true' : 'false'])->allowed;
        abort_unless($ok, 404);

        return $this->completed($request, ['message' => 'Access revoked.'], '/portal/enter');
    }
}
