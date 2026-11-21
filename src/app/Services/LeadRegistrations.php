<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brand lead visibility (D38). All methods require the route's locked, transaction-local workspace context.
 * Brand reads rely on the row policy of `lead_registrations` as the single visibility rule; this class only
 * narrows it further and projects the shared allowlist. Private pipeline tables are never read for a Brand.
 */
class LeadRegistrations
{
    public const ROSTER_PER_PAGE = 10;

    public const PER_PAGE = 25;

    /** Rolling window behind `Leads (30d)`: 30 × 24 hours on the database clock. */
    private const WINDOW = "interval '720 hours'";

    /** One database clock reading per response, so counts, lists and links in it agree. */
    public function clock(): string
    {
        return DB::selectOne('SELECT clock_timestamp() AS moment')->moment;
    }

    // ----- Brand side -------------------------------------------------------------------------

    /** Full-result figures for the Brand hub. They never depend on the roster page or its search. */
    public function metrics(string $org): array
    {
        return [
            'activePartners' => DB::table('partnerships')->where('brand_org_id', $org)->where('status', 'active')->count(),
            'leadsRegistered' => $this->visible($org)->count(),
        ];
    }

    public function roster(string $org, string $search, int $page, string $now): array
    {
        $where = "p.brand_org_id = ?::uuid AND p.status = 'active'";
        $bindings = [$org];
        if ($search !== '') {
            // Literal substring search; '%' and '_' typed by a user are not wildcards.
            $where .= ' AND strpos(lower(p.creator_name), lower(?)) > 0';
            $bindings[] = $search;
        }
        $total = (int) DB::selectOne("SELECT count(*) AS total FROM partnerships p WHERE $where", $bindings)->total;
        $page = $this->clamp($page, $total, self::ROSTER_PER_PAGE);
        $rows = DB::select($this->partnerSelect()." WHERE $where ORDER BY p.accepted_at, p.id LIMIT ? OFFSET ?",
            [$now, $now, ...$bindings, self::ROSTER_PER_PAGE, ($page - 1) * self::ROSTER_PER_PAGE]);

        return ['rows' => array_map(fn ($row) => $this->partnerRow($row), $rows), 'total' => $total, 'page' => $page, 'perPage' => self::ROSTER_PER_PAGE];
    }

    /** One of the Brand's own active partnerships, or null. Foreign and ended IDs are indistinguishable. */
    public function partner(string $org, string $relationship, string $now): ?array
    {
        $row = DB::selectOne($this->partnerSelect()." WHERE p.brand_org_id = ?::uuid AND p.status = 'active' AND p.id = ?::uuid", [$now, $now, $org, $relationship]);
        if (! $row) {
            return null;
        }
        $profile = $row->paused ? null : DB::selectOne('SELECT app_partner_shared_profile(?::uuid) AS data', [$relationship])->data;

        return [...$this->partnerRow($row), 'profile' => $profile ? json_decode($profile, true, flags: JSON_THROW_ON_ERROR) : null];
    }

    /** Partner choices for the registration filter. */
    public function partnerOptions(string $org): array
    {
        return DB::table('partnerships')->where('brand_org_id', $org)->where('status', 'active')
            ->orderByRaw('lower(creator_name)')->orderBy('id')->get(['id', 'creator_name'])->all();
    }

    /** Rows-per-page choices offered on the Brand registration list. */
    public const PAGE_SIZES = [10, 25, 50];

    /** Sort keys for the Brand registration list; the empty key is newest registration first. */
    public const SORTS = ['' => ['r.registered_at', 'desc'], 'registered_asc' => ['r.registered_at', 'asc'],
        'lead_asc' => ['lower(r.display_name)', 'asc'], 'lead_desc' => ['lower(r.display_name)', 'desc']];

    /** @param array{partner?: ?string, stage?: ?string, q?: ?string, window?: ?string, sort?: ?string} $filters */
    public function listing(string $org, array $filters, int $page, string $now, int $perPage = self::PER_PAGE): array
    {
        $query = $this->filtered($org, $filters, $now);
        $total = (clone $query)->count();
        $page = $this->clamp($page, $total, $perPage);
        [$column, $direction] = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS[''];
        $rows = $query->orderByRaw("$column $direction")->orderBy('r.id', $direction)->limit($perPage)->offset(($page - 1) * $perPage)
            ->get(['r.id', 'r.display_name', 'r.stage', 'r.registered_at', 'r.shared_updated_at', 'r.partnership_id', 'p.creator_name', 'r.summary'])
            ->map(fn ($row) => [...$this->summaryRow($row), 'summary' => $row->summary])->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * Figures for the registration list header, over every visible registration (filters do not apply):
     * all registrations, those registered in the last 30 days, partners with at least one, and those whose
     * shared details changed in the last 7 days.
     *
     * @return array{total: int, recent: int, partners: int, updatedWeek: int}
     */
    public function summary(string $org, string $now): array
    {
        $row = $this->visible($org)->selectRaw('count(*)::int AS total,
            (count(*) FILTER (WHERE r.registered_at >= ?::timestamptz - '.self::WINDOW.' AND r.registered_at <= ?::timestamptz))::int AS recent,
            count(DISTINCT r.partnership_id)::int AS partners,
            (count(*) FILTER (WHERE r.shared_updated_at > r.registered_at AND r.shared_updated_at >= ?::timestamptz - interval \'168 hours\'
                AND r.shared_updated_at <= ?::timestamptz))::int AS updated_week', [$now, $now, $now, $now])->first();

        return ['total' => (int) $row->total, 'recent' => (int) $row->recent, 'partners' => (int) $row->partners, 'updatedWeek' => (int) $row->updated_week];
    }

    /**
     * Registration counts for the stage and partner filter choices. Each facet applies every other
     * filter but its own, so a count always matches the list that choosing it would show.
     *
     * @return array{stages: array<string, int>, stageTotal: int, partners: array<string, int>, partnerTotal: int}
     */
    public function facets(string $org, array $filters, string $now): array
    {
        $count = fn (string $without, string $column) => $this->filtered($org, [...$filters, $without => ''], $now)
            ->selectRaw("$column AS facet, count(*)::int AS total")->groupBy($column)->pluck('total', 'facet')->map(fn ($n) => (int) $n)->all();
        $stages = $count('stage', 'r.stage');
        $partners = $count('partner', 'r.partnership_id');

        return ['stages' => $stages, 'stageTotal' => array_sum($stages), 'partners' => $partners, 'partnerTotal' => array_sum($partners)];
    }

    private function filtered(string $org, array $filters, string $now)
    {
        $query = $this->visible($org)->join('partnerships as p', 'p.id', '=', 'r.partnership_id');
        if (! empty($filters['partner'])) {
            // An unknown or foreign partnership narrows to nothing; it can never widen the result.
            $query->where('r.partnership_id', $filters['partner']);
        }
        if (! empty($filters['stage'])) {
            $query->where('r.stage', $filters['stage']);
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $query->whereRaw('strpos(lower(r.display_name), lower(?)) > 0', [$filters['q']]);
        }
        if (($filters['window'] ?? '') === '30d') {
            $query->whereRaw('r.registered_at >= ?::timestamptz - '.self::WINDOW.' AND r.registered_at <= ?::timestamptz', [$now, $now]);
        }

        return $query;
    }

    /** The shared allowlist of one visible registration, or null. */
    public function detail(string $org, string $id): ?array
    {
        $row = $this->visible($org)->join('partnerships as p', 'p.id', '=', 'r.partnership_id')->where('r.id', $id)
            ->first(['r.id', 'r.display_name', 'r.stage', 'r.registered_at', 'r.shared_updated_at', 'r.partnership_id', 'p.creator_name',
                'p.brand_name', 'r.summary', 'r.share_contact', 'r.contact_name', 'r.contact_email', 'p.accepted_at']);
        if (! $row) {
            return null;
        }
        // The partner card in the registration drawer: when the partnership began and how many of its leads are visible.
        $leads = $this->visible($org)->where('r.partnership_id', $row->partnership_id)->count();

        return [...$this->summaryRow($row), 'brand_name' => $row->brand_name, 'summary' => $row->summary,
            'share_contact' => (bool) $row->share_contact, 'contact_name' => $row->contact_name, 'contact_email' => $row->contact_email,
            'partner_since' => $row->accepted_at ? Carbon::parse($row->accepted_at)->toIso8601String() : null, 'partner_leads' => $leads];
    }

    private function visible(string $org)
    {
        return DB::table('lead_registrations as r')->where('r.brand_org_id', $org)->where('r.state', 'active');
    }

    private function partnerSelect(): string
    {
        return 'SELECT p.id, p.brand_name, p.creator_name, p.status, p.accepted_at, app_partnership_paused(p.id) AS paused,
                app_partner_signed_in_at(p.id) AS signed_in_at, s.leads_total, s.leads_recent, s.shared_at, s.shared_event
            FROM partnerships p
            LEFT JOIN LATERAL (
                SELECT count(*)::int AS leads_total,
                    (count(*) FILTER (WHERE r.registered_at >= ?::timestamptz - '.self::WINDOW.' AND r.registered_at <= ?::timestamptz))::int AS leads_recent,
                    max(r.shared_updated_at) AS shared_at,
                    (array_agg(r.last_event ORDER BY r.shared_updated_at DESC, r.last_event DESC))[1] AS shared_event
                FROM lead_registrations r
                WHERE r.partnership_id = p.id AND r.brand_org_id = p.brand_org_id AND r.state = \'active\'
            ) s ON true';
    }

    private function partnerRow(object $row): array
    {
        $paused = (bool) $row->paused;

        return [
            'id' => $row->id, 'brand_name' => $row->brand_name, 'creator_name' => $row->creator_name, 'status' => $row->status,
            'accepted_at' => Carbon::parse($row->accepted_at)->toIso8601String(), 'paused' => $paused,
            // A paused partnership shows no figures: a zero would wrongly read as "no registrations".
            'leads_total' => $paused ? null : (int) $row->leads_total,
            'leads_30d' => $paused ? null : (int) $row->leads_recent,
            'last_active' => $paused ? null : $this->activity($row->signed_in_at, $row->shared_at, $row->shared_event),
        ];
    }

    /** Latest of sign-in and shared lead activity. On an exact tie the lead event is reported. */
    private function activity(?string $signedIn, ?string $sharedAt, ?string $sharedEvent): ?array
    {
        $shared = $sharedAt ? Carbon::parse($sharedAt) : null;
        $login = $signedIn ? Carbon::parse($signedIn) : null;
        if ($shared && (! $login || $shared->greaterThanOrEqualTo($login))) {
            return ['at' => $shared->toIso8601String(), 'kind' => $sharedEvent === 'updated' ? 'lead_updated' : 'lead_registered'];
        }

        return $login ? ['at' => $login->toIso8601String(), 'kind' => 'signed_in'] : null;
    }

    private function summaryRow(object $row): array
    {
        return ['id' => $row->id, 'display_name' => $row->display_name, 'stage' => $row->stage,
            'registered_at' => Carbon::parse($row->registered_at)->toIso8601String(),
            'shared_updated_at' => Carbon::parse($row->shared_updated_at)->toIso8601String(),
            'partnership_id' => $row->partnership_id, 'partner_name' => $row->creator_name];
    }

    /** A page past the end (for example after the last row of the last page is removed) falls back to the last page. */
    private function clamp(int $page, int $total, int $perPage): int
    {
        return max(1, min($page, (int) ceil($total / $perPage)));
    }

    // ----- Partner side -----------------------------------------------------------------------

    public function canManage(string $org): bool
    {
        return (bool) DB::selectOne("SELECT app_has_membership(?::uuid, ARRAY['owner','admin']) AS allowed", [$org])->allowed;
    }

    /** Connected Brands this workspace can register a lead with. */
    public function brands(string $org): array
    {
        return array_map(fn ($row) => ['id' => $row->id, 'brand_name' => $row->brand_name, 'paused' => (bool) $row->paused, 'shared' => (int) $row->shared],
            DB::table('partnerships AS p')->where('p.creator_org_id', $org)->where('p.status', 'active')->orderByRaw('lower(p.brand_name)')->orderBy('p.id')
                ->get(['p.id', 'p.brand_name', DB::raw('app_partnership_paused(p.id) AS paused'),
                    DB::raw("(SELECT count(*) FROM lead_registrations r WHERE r.creator_org_id = p.creator_org_id AND r.partnership_id = p.id AND r.state = 'active') AS shared")])->all());
    }

    /**
     * The workspace's open leads a manager can share, each with the partnerships it is already shared with.
     * Lost leads are left out; only workspace owners and admins receive this list.
     */
    public function shareable(string $org, int $limit = 200): array
    {
        $rows = DB::table('pipeline_entries')->where('org_id', $org)->where('stage', '<>', 'lost')
            ->orderByDesc('updated_at')->orderBy('id')->limit($limit)->get(['id', 'brand_name', 'contact_name', 'contact_email', 'stage']);
        $shared = DB::table('lead_registrations')->where('creator_org_id', $org)->where('state', 'active')
            ->whereIn('pipeline_entry_id', $rows->pluck('id'))->get(['pipeline_entry_id', 'partnership_id'])->groupBy('pipeline_entry_id');

        return $rows->map(fn ($row) => [...(array) $row, 'registered_with' => ($shared[$row->id] ?? collect())->pluck('partnership_id')->all()])->all();
    }

    /** The workspace's own lead offered for registration, with the Brands it is already shared with. */
    public function source(string $org, string $entry): ?array
    {
        $row = DB::table('pipeline_entries')->where('org_id', $org)->where('id', $entry)->first(['id', 'brand_name', 'contact_name', 'contact_email', 'stage']);

        return $row ? [...(array) $row, 'registered_with' => DB::table('lead_registrations')->where('creator_org_id', $org)
            ->where('pipeline_entry_id', $entry)->where('state', 'active')->pluck('partnership_id')->all()] : null;
    }

    public const OWN_STATES = ['shared', 'not_shared', 'all'];

    /**
     * A page of the workspace's own registrations. `state` is `shared` (active, including paused Brands),
     * `not_shared` (withdrawn for any reason) or `all`; `brand` is a partnership ID and `q` searches lead and Brand names.
     * Counts follow the Brand and search filters.
     */
    public function own(string $org, int $page, array $filters = []): array
    {
        $query = DB::table('lead_registrations as r')->leftJoin('partnerships as p', 'p.id', '=', 'r.partnership_id')->where('r.creator_org_id', $org);
        if (! empty($filters['brand'])) {
            $query->where('r.partnership_id', $filters['brand']);
        }
        if (($filters['q'] ?? '') !== '') {
            $query->whereRaw("strpos(lower(r.display_name || ' ' || coalesce(p.brand_name, '')), lower(?)) > 0", [$filters['q']]);
        }
        $counts = (clone $query)->selectRaw("count(*) FILTER (WHERE r.state = 'active') AS shared, count(*) FILTER (WHERE r.state <> 'active') AS not_shared, count(*) AS total")->first();
        $counts = ['shared' => (int) $counts->shared, 'not_shared' => (int) $counts->not_shared, 'all' => (int) $counts->total];
        match ($filters['state'] ?? 'all') {
            'shared' => $query->where('r.state', 'active'),
            'not_shared' => $query->where('r.state', '<>', 'active'),
            default => null,
        };
        $total = $counts[$filters['state'] ?? 'all'];
        $page = $this->clamp($page, $total, self::PER_PAGE);
        $rows = $query->orderByDesc('r.registered_at')->orderByDesc('r.id')->limit(self::PER_PAGE)->offset(($page - 1) * self::PER_PAGE)
            ->get(['r.id', 'r.display_name', 'r.summary', 'r.share_contact', 'r.stage', 'r.state', 'r.withdrawn_reason', 'r.registered_at', 'r.version',
                'r.pipeline_entry_id', 'p.brand_name', DB::raw('CASE WHEN p.id IS NULL THEN false ELSE app_partnership_paused(p.id) END AS paused')])
            ->map(fn ($row) => ['id' => $row->id, 'display_name' => $row->display_name, 'summary' => $row->summary, 'share_contact' => (bool) $row->share_contact,
                'stage' => $row->stage, 'state' => $row->state, 'withdrawn_reason' => $row->withdrawn_reason, 'version' => $row->version,
                'registered_at' => Carbon::parse($row->registered_at)->toIso8601String(), 'entry_id' => $row->pipeline_entry_id,
                // The name of an ended partnership's Brand is no longer shared with this workspace.
                'brand_name' => $row->brand_name, 'paused' => (bool) $row->paused])->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => self::PER_PAGE, 'counts' => $counts];
    }

    public function profile(string $org): array
    {
        $row = DB::table('partner_shared_profiles')->where('org_id', $org)->first(['contact_name', 'contact_email', 'website_url', 'description', 'version']);

        return $row ? (array) $row : ['contact_name' => null, 'contact_email' => null, 'website_url' => null, 'description' => '', 'version' => 0];
    }

    /** @return array{status: string, id: string} */
    public function register(string $partnership, string $entry, string $name, string $summary, bool $contact): array
    {
        $result = json_decode(DB::selectOne('SELECT app_register_lead(?::uuid,?::uuid,?::uuid,?::text,?::text,?::boolean) AS data',
            [(string) Str::uuid(), $partnership, $entry, $name, $summary, $contact ? 'true' : 'false'])->data, true, flags: JSON_THROW_ON_ERROR);
        abort_if($result['status'] === 'denied', 403);
        abort_if($result['status'] === 'missing', 404);
        if ($result['status'] === 'unavailable') {
            throw ValidationException::withMessages(['partnership' => 'This Brand is not available for registration. Choose a connected Brand that is not paused.']);
        }

        return $result;
    }

    public function update(string $id, int $version, string $name, string $summary, bool $contact): void
    {
        $this->settle(DB::selectOne('SELECT app_update_lead_registration(?::uuid,?::integer,?::text,?::text,?::boolean) AS status',
            [$id, $version, $name, $summary, $contact ? 'true' : 'false'])->status, 'registration');
    }

    public function withdraw(string $id, int $version): void
    {
        $this->settle(DB::selectOne('SELECT app_withdraw_lead_registration(?::uuid,?::integer) AS status', [$id, $version])->status, 'registration');
    }

    public function saveProfile(int $version, array $data): void
    {
        $this->settle(DB::selectOne('SELECT app_save_shared_profile(?::integer,?::text,?::text,?::text,?::text) AS status',
            [$version, $data['contact_name'] ?? '', $data['contact_email'] ?? '', $data['website_url'] ?? '', $data['description'] ?? ''])->status, 'shared profile');
    }

    private function settle(string $status, string $subject): void
    {
        abort_if($status === 'denied', 403);
        abort_if($status === 'missing', 404);
        if ($status === 'stale') {
            throw ValidationException::withMessages(['version' => 'This '.$subject.' changed in another session. Reload the page before saving again.'])->status(409);
        }
    }
}
