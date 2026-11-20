<?php

namespace App\Http\Controllers;

use App\Services\Pipeline;
use App\Services\PublicPresence;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PublicPresenceController extends Controller
{
    public function index(Request $request, Pipeline $pipeline, PublicPresence $presence): mixed
    {
        if ($request->is('public-presence') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect('/partner/media-kit'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', PublicPresence::LINK_STATUSES)], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $status = $filters['status'] ?? 'active';
        $org = $request->session()->get('active_org_id');
        $kit = DB::table('media_kits')->where('org_id', $org)->first(['id', 'title', 'bio', 'website', 'published', 'version', 'views', 'social_links']);
        if ($kit) {
            $kit->bio = RichText::fromStored($kit->bio);
            $kit->social_links = array_map(fn ($link) => ['label' => $link['label'] ?? '', 'url' => $link['url'] ?? ''], json_decode($kit->social_links, true));
            $kit->public_url = url('/kits/'.$kit->id);
        }
        $links = $presence->links($org, $status, (int) ($filters['page'] ?? 1));
        $props = ['workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')], 'canWrite' => $pipeline->canWrite((int) $request->user()->id, $org),
            'kit' => $kit,
            'links' => $links,
            'summary' => ['views' => (int) ($kit->views ?? 0), 'activeLinks' => $links['counts']['active'], 'leads' => $presence->receivedLeads($org)],
            'filters' => ['status' => $status],
            'issuedLink' => $request->session()->has('issued_link') ? Crypt::decryptString($request->session()->get('issued_link')) : null];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($props) : Inertia::render('PublicPresence', $props);
    }

    public function saveKit(Request $request, PublicPresence $presence): mixed
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'bio' => ['nullable', 'string', 'max:'.RichText::MAX_HTML], 'website' => ['nullable', 'url:http,https', 'max:500'],
            'published' => ['required', 'boolean'], 'consent' => ['exclude_unless:published,true', 'required', 'accepted'],
            'social_links' => ['nullable', 'array', 'max:8'], 'social_links.*' => ['array:label,url'],
            'social_links.*.label' => ['required', 'string', 'max:60'], 'social_links.*.url' => ['required', 'url:http,https', 'max:500'], 'version' => ['required', 'integer', 'min:0', 'max:2147483646']]);
        $bio = RichText::sanitize($d['bio'] ?? '');
        if (mb_strlen(RichText::text($bio)) > RichText::MAX_TEXT || strlen($bio) > RichText::MAX_HTML) {
            throw ValidationException::withMessages(['bio' => 'The public introduction may not be longer than 5,000 characters.']);
        }
        $presence->saveKit((int) $request->user()->id, $request->session()->get('active_org_id'), ['title' => $d['title'], 'bio' => $bio, 'website' => $d['website'] ?? '', 'published' => (bool) $d['published'], 'social_links' => json_encode($d['social_links'] ?? [], JSON_THROW_ON_ERROR)], (int) $d['version']);

        return $this->completed($request, ['message' => 'Media kit saved'], '/partner/media-kit');
    }

    public function createLink(Request $request, PublicPresence $presence): mixed
    {
        $label = $request->validate(['label' => ['nullable', 'string', 'max:80']])['label'] ?? '';
        $link = $presence->leadLink((int) $request->user()->id, $request->session()->get('active_org_id'), trim($label));

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($link, 201) : redirect('/partner/media-kit')->with('issued_link', Crypt::encryptString($link['url']))->with('status', 'Lead link created');
    }

    public function revoke(Request $request, PublicPresence $presence, string $record): mixed
    {
        $back = $request->validate(['status' => ['nullable', 'in:'.implode(',', PublicPresence::LINK_STATUSES)], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $presence->revoke((int) $request->user()->id, $request->session()->get('active_org_id'), $record);
        $query = array_filter(['status' => $back['status'] ?? null, 'page' => ($back['page'] ?? 1) > 1 ? $back['page'] : null]);

        return $this->completed($request, ['message' => 'Lead link revoked'], '/partner/media-kit'.($query ? '?'.http_build_query($query) : ''));
    }

    public function kit(string $record): mixed
    {
        abort_if(DB::selectOne("SELECT app_public_link_suspended('kit',?) AS suspended", [$record])->suspended, 404);
        $kit = DB::selectOne('SELECT * FROM app_public_kit(?::uuid)', [$record]);
        abort_unless($kit, 404);

        $kit->professional = DB::selectOne('SELECT app_public_profile_experience(?::uuid) AS experience', [$record])->experience === 'professional';
        $kit->social_links = json_decode($kit->social_links, true);
        $kit->bio_html = RichText::fromStored($kit->bio);
        $kit->description = mb_substr(RichText::text($kit->bio_html), 0, 160);

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
