<?php

namespace App\Http\Controllers;

use App\Http\Queries\PipelinePage;
use App\Models\User;
use App\Services\NotificationOutbox;
use App\Services\PartnershipInvitation;
use App\Services\Pipeline;
use App\Services\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PortalController extends Controller
{
    // Portal entry preferences and their workspace kinds and homes; stored kind values are unchanged (D29, D32).
    private const KINDS = ['brand' => 'brand', 'pro' => 'creator', 'partner' => 'partner'];

    private const HOMES = ['brand' => '/brand', 'creator' => '/pro/leads', 'partner' => '/partner'];

    public function enter(Request $request, Workspaces $workspaces)
    {
        $user = $request->user();
        $provisioned = (bool) $user->registration_workspace_id;
        if ($provisioned) {
            $id = $workspaces->create((int) $user->id, $user->name, $user->registration_workspace_kind, $user->registration_workspace_id);
            $request->session()->put('active_org_id', $id);
            $user->forceFill(['registration_workspace_id' => null, 'registration_workspace_kind' => null])->save();
        }
        // Invitations and sign-up links connect brands to the account's one Partner workspace, created on first use (D32).
        if ($request->session()->pull('partnership_workspace') && PartnershipInvitation::pendingFor($request, $user)) {
            $partner = collect($workspaces->listing((int) $user->id))->first(fn ($row) => $row->kind === 'partner' && $row->role === 'owner');
            $request->session()->put('active_org_id', $partner->id ?? $workspaces->create((int) $user->id, $user->name, 'partner'));
        }
        $listing = $workspaces->listing((int) $request->user()->id);
        $kind = self::KINDS[$request->session()->get('portal')] ?? null;
        $selected = collect($listing)->first(fn ($row) => $row->id === $request->session()->get('active_org_id') && (! $kind || $row->kind === $kind));
        if (! $selected) {
            $matching = collect($listing)->filter(fn ($row) => ! $kind || $row->kind === $kind);
            $selected = $matching->count() === 1 ? $matching->first() : null;
        }
        if (! $selected) {
            return redirect('/workspaces');
        }
        $request->session()->put('active_org_id', $selected->id);
        $request->session()->regenerate();

        $pending = $selected->kind !== 'partner' ? null : ($request->session()->has('partnership_token') ? '/partnerships/accept' : ($request->session()->has('partnership_join') ? '/join' : null));

        return redirect($pending ?? self::HOMES[$selected->kind]);
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
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        $recipient = DB::selectOne('SELECT app_partner_invitation_recipient(?::text) AS email', [hash('sha256', $data['token'])])->email;
        if (! $recipient) {
            $message = 'This invitation is expired, revoked or already accepted. Ask the brand to send a new invitation.';
            abort_if($request->expectsJson() && ! $request->header('X-Inertia'), 404, $message);

            return back()->withErrors(['token' => $message]);
        }
        $request->session()->forget('partnership_join');
        $request->session()->put('partnership_token', ['encrypted' => encrypt($data['token']), 'recipient' => encrypt($recipient), 'expires' => time() + 900]);
        $request->session()->put('portal', 'partner');
        $request->session()->put('partnership_workspace', true);
        if (! $request->user()) {
            // Existing identities sign in; new recipients register a separate account. Both use a Partner workspace.
            $existing = User::whereRaw('lower(email) = ?', [$recipient])->exists();

            return redirect($existing ? '/partner/login' : '/partner/register');
        }

        return redirect($request->user()->hasVerifiedEmail() ? '/portal/enter' : '/verify-email');
    }

    public function index(Request $request, Pipeline $pipeline)
    {
        $org = $request->session()->get('active_org_id');
        $workspace = DB::table('organizations')->where('id', $org)->first(['id', 'name', 'kind']);
        $portal = $request->is('brand') ? 'brand' : ($request->is('partner') ? 'partner' : 'pro');
        if (! $workspace || $workspace->kind !== self::KINDS[$portal]) {
            return redirect('/workspaces');
        }
        if ($request->is('pro') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect('/pro/leads'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }
        $relationships = DB::table('partnerships')->orderBy('accepted_at')->get(['id', 'brand_name', 'creator_name', 'status', 'accepted_at'])->all();
        $canManage = (bool) DB::selectOne("SELECT app_has_membership(?::uuid, ARRAY['owner','admin']) AS allowed", [$org])->allowed;
        $joinLink = $portal === 'brand' && $canManage ? DB::table('partner_join_links')->where('brand_org_id', $org)->whereNull('revoked_at')->first(['sealed_code', 'created_at']) : null;
        $data = ['portal' => $portal, 'workspace' => $workspace, 'relationships' => $relationships, 'canManage' => $canManage,
            'joinLink' => $joinLink ? [...PartnerJoinController::share(decrypt($joinLink->sealed_code)), 'created_at' => $joinLink->created_at] : null,
            'invitations' => DB::table('partner_invitations as i')->leftJoin('notification_outbox as n', 'n.subject_id', '=', 'i.id')
                ->orderByDesc('i.created_at')->limit(100)->get(['i.id', 'i.status', 'i.expires_at', 'n.state as email_state'])->all(),
            'pipeline' => $portal === 'pro' ? app(PipelinePage::class)->data($request, $pipeline) : null,
            'closedDeals' => $portal === 'pro' ? $pipeline->closedDeals($org) : null];
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($data);
        }
        if ($portal === 'partner') {
            return Inertia::render('PartnerPortal', ['workspace' => $workspace, 'relationships' => $relationships, 'canManage' => $canManage]);
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
        $message = ! empty($data['send_email']) ? 'Invitation sent to '.$data['email'] : 'Invitation link created';

        return redirect('/brand')->with('partner_invite_url', encrypt($url))->with('status', $message);
    }

    public function accept(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'consent' => ['accepted']]);
        $ok = DB::selectOne('SELECT app_accept_partner(?::text,?::uuid) AS allowed', [hash('sha256', $data['token']), (string) Str::uuid()])->allowed;
        if (! $ok) {
            $message = 'This invitation cannot be accepted by the signed-in account. Sign in with the verified email address the invitation was sent to. Expired, revoked or already accepted invitations, or an existing partnership with this brand, also prevent acceptance.';
            abort_if($request->expectsJson() && ! $request->header('X-Inertia'), 404, $message);

            // Browser visits keep the form and explain the refusal instead of the generic 404 page.
            return back()->withErrors(['token' => $message]);
        }

        $request->session()->forget('partnership_token');

        return $this->completed($request, ['message' => 'Partnership accepted'], '/partner');
    }

    public function revoke(Request $request, string $record)
    {
        $ok = DB::selectOne('SELECT app_revoke_partner(?::uuid,?::uuid,?::boolean) AS allowed', [$record, (string) Str::uuid(), $request->is('partner-invitations/*') ? 'true' : 'false'])->allowed;
        abort_unless($ok, 404);

        return $this->completed($request, ['message' => $request->is('partner-invitations/*') ? 'Invitation revoked' : 'Partnership ended'], '/portal/enter');
    }
}
