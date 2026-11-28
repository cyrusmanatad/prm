<?php

namespace App\Http\Controllers;

use App\Enums\PipelineStage;
use App\Services\Audit;
use App\Services\Incentives;
use App\Services\LeadRegistrations;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CampaignController extends Controller
{
    public function index(Request $request, Incentives $incentives, string $experience, ?string $campaign = null): mixed
    {
        $workspace = $this->workspace($request, $experience);
        $brand = $experience === 'brand';
        $canWrite = $brand && app(LeadRegistrations::class)->canManage($workspace->id);
        // Partnerships are readable only while active, so a shared campaign without a readable partnership has ended.
        $query = DB::table('campaigns as c')->leftJoin('partnerships as p', 'p.id', '=', 'c.partnership_id')
            ->select('c.id', 'c.title', 'c.due_date', 'c.partnership_id', 'c.created_at', 'c.shared_at', 'p.brand_name', 'p.creator_name')
            ->selectRaw("CASE WHEN c.partnership_id IS NULL THEN 'draft' WHEN p.id IS NULL THEN 'ended' ELSE 'shared' END AS status")
            // Linked registrations the reader may see (D43): the Brand's visible ones, or the Partner's own active ones.
            ->selectRaw("(SELECT count(*) FROM lead_registrations r WHERE r.campaign_id = c.id AND r.state = 'active')::int AS leads")
            // The latest incentive on this brief that the reader may see (D44).
            ->selectRaw("(SELECT jsonb_build_object('id', i.id, 'title', i.title, 'state', i.state) FROM incentives i WHERE i.campaign_id = c.id
                ORDER BY (i.state = 'shared') DESC, i.created_at DESC, i.id LIMIT 1) AS incentive")
            ->where($brand ? 'c.org_id' : 'p.creator_org_id', $workspace->id);
        $selected = null;
        $incentive = null;
        $leads = [];
        $listing = null;
        $partners = [];
        $counts = null;
        $filter = 'all';
        if ($campaign !== null) {
            abort_unless($selected = $query->addSelect('c.brief', 'c.version')->where('c.id', $campaign)->first(), 404);
            $selected->incentive = $selected->incentive ? json_decode($selected->incentive, true) : null;
            $leads = $this->leads($campaign);
            // The incentive card (D44): the Brand's offer, or the Partner's own view of it with claimable leads.
            if ($selected->incentive) {
                $offer = $brand ? $incentives->brandIncentive($workspace->id, $selected->incentive['id'])
                    : collect($incentives->partnerOffers($workspace->id))->firstWhere('id', $selected->incentive['id']);
                $incentive = $offer ? array_diff_key($offer, ['awards' => 0]) : null;
                if (! $brand && $incentive) {
                    $states = $incentives->awardStatesForLeads(array_column($leads, 'id'));
                    $leads = array_map(fn ($lead) => [...$lead, 'award' => $states[$lead['id']] ?? null], $leads);
                }
            }
            if ($canWrite && ! $selected->partnership_id) {
                $partners = DB::select('SELECT * FROM app_campaign_partners()');
            }
        } else {
            $filters = $request->validate([
                'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
                'status' => ['sometimes', 'string', 'in:all,draft,shared,ended'],
            ]);
            if ($brand) {
                $filter = $filters['status'] ?? 'all';
                $row = DB::query()->fromSub(clone $query, 'x')->selectRaw(
                    "count(*) AS total, count(*) FILTER (WHERE status = 'draft') AS draft, count(*) FILTER (WHERE status = 'shared') AS shared, count(*) FILTER (WHERE status = 'ended') AS ended"
                )->first();
                $counts = ['all' => (int) $row->total, 'draft' => (int) $row->draft, 'shared' => (int) $row->shared, 'ended' => (int) $row->ended];
                match ($filter) {
                    'draft' => $query->whereNull('c.partnership_id'),
                    'shared' => $query->whereNotNull('p.id'),
                    'ended' => $query->whereNotNull('c.partnership_id')->whereNull('p.id'),
                    default => null,
                };
            }
            $listing = $query->orderByDesc('c.created_at')->orderBy('c.id')->paginate(25)->withQueryString();
            $listing->getCollection()->transform(function ($row) {
                $row->incentive = $row->incentive ? json_decode($row->incentive, true) : null;

                return $row;
            });
            if ($brand) {
                $counts['leads'] = (int) DB::table('lead_registrations as r')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')
                    ->where('c.org_id', $workspace->id)->where('r.state', 'active')->count();
                $counts['awards'] = (int) DB::table('incentive_awards')->where('brand_org_id', $workspace->id)->where('state', 'pending')->count();
            }
        }
        $canManage = app(LeadRegistrations::class)->canManage($workspace->id);
        $stages = PipelineStage::options();
        $data = compact('workspace', 'experience', 'canWrite', 'canManage', 'selected', 'leads', 'incentive', 'listing', 'partners', 'counts', 'filter', 'stages');

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($data) : Inertia::render('Campaigns', $data);
    }

    public function store(Request $request, Audit $audit): mixed
    {
        $workspace = $this->workspace($request, 'brand');
        abort_unless(app(LeadRegistrations::class)->canManage($workspace->id), 403);
        $title = $request->validate(['title' => ['required', 'string', 'max:160']])['title'];
        $id = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $id, 'org_id' => $workspace->id, 'title' => $title]);
        $audit->record($request->user()->id, $workspace->id, 'campaign.created', $id);

        return $request->expectsJson() ? response()->json(['id' => $id], 201) : redirect('/brand/campaigns/'.$id);
    }

    public function update(Request $request, string $campaign, string $action, Audit $audit): mixed
    {
        $workspace = $this->workspace($request, 'brand');
        abort_unless(app(LeadRegistrations::class)->canManage($workspace->id), 403);
        $share = $action === 'share';
        $required = $share ? 'required' : 'nullable';
        $data = $request->validate([
            'version' => ['required', 'integer', 'between:1,2147483647'], 'brief' => [$required, 'string', 'max:8000'],
            'due_date' => [$required, 'date_format:Y-m-d', 'after_or_equal:0001-01-01'], 'partner' => $share ? ['required', 'uuid'] : ['prohibited'],
        ]);
        $result = DB::selectOne('SELECT app_save_campaign(?,?,?,?,?) AS result', [
            $campaign, $data['version'], $data['brief'] ?? '', $data['due_date'] ?? null, $share ? $data['partner'] : null,
        ])->result;
        abort_if($result === 'missing', 404);
        abort_if($result === 'denied', 403);
        if ($result === 'unavailable') {
            throw ValidationException::withMessages(['partner' => 'Choose an active Partner who can receive this brief.']);
        }
        if ($result === 'stale') {
            throw ValidationException::withMessages(['version' => 'The campaign changed. Reopen it to see the current brief.']);
        }
        $audit->record($request->user()->id, $workspace->id, $share ? 'campaign.shared' : 'campaign.updated', $campaign);

        return $request->expectsJson() ? response()->json(['id' => $campaign]) : redirect('/brand/campaigns/'.$campaign)->with('status', $share ? 'Brief shared.' : 'Draft saved.');
    }

    /**
     * Registrations linked to this campaign that the reader may see (D43), newest first. Row policies decide:
     * the Brand sees active registrations of available relationships; the Partner sees its own active ones.
     */
    private function leads(string $campaign): array
    {
        return DB::table('lead_registrations as r')->where('r.campaign_id', $campaign)->where('r.state', 'active')
            ->orderByDesc('r.registered_at')->orderByDesc('r.id')->limit(200)
            ->get(['r.id', 'r.display_name', 'r.stage', 'r.registered_at', 'r.campaign_linked_at', 'r.campaign_confirmed_at'])
            ->map(fn ($row) => ['id' => $row->id, 'display_name' => $row->display_name, 'stage' => $row->stage,
                'registered_at' => Carbon::parse($row->registered_at)->toIso8601String(),
                'linked_at' => Carbon::parse($row->campaign_linked_at)->toIso8601String(),
                'confirmed' => $row->campaign_confirmed_at !== null])->all();
    }

    private function workspace(Request $request, string $experience): object
    {
        $workspace = DB::table('organizations')->where('id', $request->session()->get('active_org_id'))
            ->first(['id', 'name', 'kind', 'experience']);
        abort_unless($workspace && ($experience === 'brand' ? $workspace->kind === 'brand'
            : $workspace->kind === 'creator' && $workspace->experience === 'partner'), 404);

        return $workspace;
    }
}
