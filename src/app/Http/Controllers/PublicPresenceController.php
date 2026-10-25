<?php

namespace App\Http\Controllers;

use App\Services\Pipeline;
use App\Services\PublicPresence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PublicPresenceController extends Controller
{
    public function index(Request $request, Pipeline $pipeline): mixed
    {
        if ($request->is('public-presence') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect('/partner/media-kit'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        $org = $request->session()->get('active_org_id');
        $props = ['workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')], 'canWrite' => $pipeline->canWrite((int) $request->user()->id, $org),
            'kit' => DB::table('media_kits')->where('org_id', $org)->first(['id', 'title', 'bio', 'website', 'published', 'version', 'views', 'social_links']),
            'links' => DB::table('lead_links')->where('org_id', $org)->orderByDesc('created_at')->get(['id', 'expires_at', 'revoked_at'])->all(),
            'issuedLink' => $request->session()->has('issued_link') ? Crypt::decryptString($request->session()->get('issued_link')) : null];

        if ($props['kit']) {
            $props['kit']->social_links = array_map(fn ($link) => ['label' => $link['label'] ?? '', 'url' => $link['url'] ?? ''], json_decode($props['kit']->social_links, true));
        }

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($props) : Inertia::render('PublicPresence', $props);
    }

    public function saveKit(Request $request, PublicPresence $presence): mixed
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'bio' => ['nullable', 'string', 'max:5000'], 'website' => ['nullable', 'url:http,https', 'max:500'],
            'published' => ['required', 'boolean'], 'consent' => ['exclude_unless:published,true', 'required', 'accepted'],
            'social_links' => ['nullable', 'array', 'max:8'], 'social_links.*' => ['array:label,url'],
            'social_links.*.label' => ['required', 'string', 'max:60'], 'social_links.*.url' => ['required', 'url:http,https', 'max:500'], 'version' => ['required', 'integer', 'min:0', 'max:2147483646']]);
        $presence->saveKit((int) $request->user()->id, $request->session()->get('active_org_id'), ['title' => $d['title'], 'bio' => $d['bio'] ?? '', 'website' => $d['website'] ?? '', 'published' => (bool) $d['published'], 'social_links' => json_encode($d['social_links'] ?? [], JSON_THROW_ON_ERROR)], (int) $d['version']);

        return $this->completed($request, ['message' => 'Media kit saved'], '/partner/media-kit');
    }

    public function createLink(Request $request, PublicPresence $presence): mixed
    {
        $link = $presence->leadLink((int) $request->user()->id, $request->session()->get('active_org_id'));

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($link, 201) : redirect('/partner/media-kit')->with('issued_link', Crypt::encryptString($link['url']))->with('status', 'Lead link created');
    }

    public function revoke(Request $request, PublicPresence $presence, string $record): mixed
    {
        $presence->revoke((int) $request->user()->id, $request->session()->get('active_org_id'), $record);

        return $this->completed($request, ['message' => 'Lead link revoked'], '/partner/media-kit');
    }

    public function kit(string $record): mixed
    {
        $kit = DB::selectOne('SELECT * FROM app_public_kit(?::uuid)', [$record]);
        abort_unless($kit, 404);

        $kit->social_links = json_decode($kit->social_links, true);

        return response()->view('media-kit', ['kit' => $kit])->header('X-Content-Type-Options', 'nosniff');
    }

    public function capture(Request $request, PublicPresence $presence): mixed
    {
        $d = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'idempotency_key' => ['required', 'uuid'],
            'brand_name' => ['required', 'string', 'max:160'], 'contact_name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255'], 'consent' => ['required', 'accepted']]);
        $presence->capture($d['token'], $d['idempotency_key'], ['brand_name' => $d['brand_name'], 'contact_name' => $d['contact_name'], 'email' => $d['email']]);

        return $this->completed($request, ['message' => 'Contact details shared'], '/lead');
    }
}
