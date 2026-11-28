<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brand incentives (D44). Every read relies on the row policies of the four incentive tables and of
 * lead_registrations; methods only narrow and project. Every write goes through a guarded database function.
 */
class Incentives
{
    /** Stage order of the qualifying rule. Lost never qualifies. */
    public const STAGES = ['lead', 'pitched', 'negotiating', 'onboarded', 'active', 'repeat-client'];

    public const AWARD_STATES = ['pending', 'approved', 'fulfilled', 'rejected', 'cancelled'];

    // ----- Brand side -------------------------------------------------------------------------

    /** Header figures over every incentive and award of the Brand. Money is summed per currency. */
    public function brandFigures(string $org): array
    {
        $offers = (int) DB::table('incentives')->where('org_id', $org)->where('state', 'shared')->count();
        $review = (int) DB::table('incentive_awards')->where('brand_org_id', $org)->where('state', 'pending')->count();
        $sum = fn (string $state, bool $thisYear) => DB::table('incentive_awards')->where('incentive_awards.brand_org_id', $org)
            ->where('incentive_awards.state', $state)->whereNotNull('incentive_awards.amount_minor')->when($thisYear, fn ($q) => $q->whereRaw("date_trunc('year', fulfilled_on) = date_trunc('year', current_date)"))
            ->join('incentives as i', 'i.id', '=', 'incentive_awards.incentive_id')
            ->groupBy('i.currency')->orderBy('i.currency')->get([DB::raw('i.currency'), DB::raw('sum(incentive_awards.amount_minor)::text AS total')])
            ->map(fn ($row) => ['currency' => $row->currency, 'minor' => $row->total])->all();

        return ['offers' => $offers, 'review' => $review, 'owed' => $sum('approved', false), 'delivered' => $sum('fulfilled', true),
            'owedCount' => (int) DB::table('incentive_awards')->where('brand_org_id', $org)->where('state', 'approved')->count()];
    }

    /** @return array{rows: list<array>, counts: array<string, int>} */
    public function offers(string $org, string $status): array
    {
        $base = DB::table('incentives as i')->where('i.org_id', $org);
        $counts = (clone $base)->selectRaw("count(*) AS total, count(*) FILTER (WHERE state = 'draft') AS draft,
            count(*) FILTER (WHERE state = 'shared') AS shared, count(*) FILTER (WHERE state = 'ended') AS ended")->first();
        $rows = $base->when($status !== 'all', fn ($q) => $q->where('i.state', $status))
            ->leftJoin('campaigns as c', 'c.id', '=', 'i.campaign_id')
            ->orderByRaw("(i.state = 'shared') DESC")->orderByDesc('i.created_at')->orderBy('i.id')->limit(200)
            ->get(['i.*', 'c.title as campaign_title'])->map(fn ($row) => $this->offerRow($row))->all();

        return ['rows' => $rows, 'counts' => ['all' => (int) $counts->total, 'draft' => (int) $counts->draft,
            'shared' => (int) $counts->shared, 'ended' => (int) $counts->ended]];
    }

    /** One Brand incentive with its audience and awards, or null. */
    public function brandIncentive(string $org, string $id): ?array
    {
        $row = DB::table('incentives as i')->leftJoin('campaigns as c', 'c.id', '=', 'i.campaign_id')
            ->where('i.org_id', $org)->where('i.id', $id)->first(['i.*', 'c.title as campaign_title', 'c.partnership_id as campaign_partnership']);
        if (! $row) {
            return null;
        }
        $offer = $this->offerRow($row);
        $granted = DB::table('incentive_awards')->where('incentive_id', $id)->whereIn('state', ['approved', 'fulfilled'])
            ->groupBy('partnership_id')->selectRaw('partnership_id, count(*)::int AS granted')->pluck('granted', 'partnership_id');
        $offer['audience'] = array_map(fn ($partner) => [...$partner, 'granted' => (int) ($granted[$partner['id']] ?? 0)], $offer['audience']);
        $offer['awards'] = $this->brandAwards($org, ['incentive' => $id, 'state' => 'all'])['rows'];
        $offer['campaign_partnership'] = $row->campaign_partnership;

        return $offer;
    }

    /** @param array{incentive?: string, state?: string} $filters */
    public function brandAwards(string $org, array $filters): array
    {
        $base = DB::table('incentive_awards as a')->where('a.brand_org_id', $org)
            ->when(! empty($filters['incentive']), fn ($q) => $q->where('a.incentive_id', $filters['incentive']));
        $counts = (clone $base)->selectRaw("count(*) AS total, count(*) FILTER (WHERE state = 'pending') AS pending,
            count(*) FILTER (WHERE state = 'approved') AS approved, count(*) FILTER (WHERE state = 'fulfilled') AS fulfilled,
            count(*) FILTER (WHERE state IN ('rejected','cancelled')) AS closed")->first();
        $state = $filters['state'] ?? 'pending';
        $rows = $base->join('incentives as i', 'i.id', '=', 'a.incentive_id')
            ->when($state === 'closed', fn ($q) => $q->whereIn('a.state', ['rejected', 'cancelled']))
            ->when(! in_array($state, ['all', 'closed'], true), fn ($q) => $q->where('a.state', $state))
            ->orderByRaw("(a.state = 'pending') DESC")->orderByDesc('a.claimed_at')->orderBy('a.id')->limit(200)
            ->get(['a.*', 'i.title as incentive_title', 'i.reward_type', 'i.currency', 'i.reward_text',
                DB::raw('(SELECT p.creator_name FROM partnerships p WHERE p.id = a.partnership_id) AS partner_name'),
                DB::raw('(SELECT count(*) FROM incentive_award_registrations ar WHERE ar.award_id = a.id)::int AS lead_count')])
            ->map(fn ($row) => $this->awardRow($row))->all();

        $counts = array_map('intval', (array) $counts);

        return ['rows' => $rows, 'counts' => ['all' => $counts['total'], ...array_diff_key($counts, ['total' => 0])]];
    }

    /** The award review panel: the claim, its cited leads with a live rule check, cap use and history. */
    public function brandAward(string $org, string $id): ?array
    {
        $row = DB::table('incentive_awards as a')->join('incentives as i', 'i.id', '=', 'a.incentive_id')
            ->where('a.brand_org_id', $org)->where('a.id', $id)
            ->first(['a.*', 'i.title as incentive_title', 'i.reward_type', 'i.currency', 'i.reward_text', 'i.amount_minor as offer_amount',
                'i.rule_stage', 'i.campaign_id', 'i.cap_per_partner', 'i.cap_total', 'i.leads_per_award',
                DB::raw('(SELECT p.creator_name FROM partnerships p WHERE p.id = a.partnership_id) AS partner_name'),
                DB::raw('(SELECT c.title FROM campaigns c WHERE c.id = i.campaign_id) AS campaign_title')]);
        if (! $row) {
            return null;
        }
        $counts = DB::selectOne("SELECT count(*) FILTER (WHERE state IN ('approved','fulfilled'))::int AS total,
            count(*) FILTER (WHERE state IN ('approved','fulfilled') AND partnership_id = ?::uuid)::int AS partner
            FROM incentive_awards WHERE incentive_id = ?::uuid", [$row->partnership_id, $row->incentive_id]);
        $leads = $this->citedLeads($id, $row->rule_stage, $row->campaign_id);

        return [...$this->awardRow($row), 'offer_amount' => $row->offer_amount === null ? null : (string) $row->offer_amount,
            'rule_stage' => $row->rule_stage, 'campaign_title' => $row->campaign_title, 'campaign_id' => $row->campaign_id,
            'cap_per_partner' => $row->cap_per_partner, 'cap_total' => $row->cap_total, 'granted_partner' => $counts->partner,
            'granted_total' => $counts->total, 'leads' => $leads];
    }

    /** Partners a Brand can choose as an audience: the same rule as a campaign recipient. */
    public function partnerChoices(): array
    {
        return array_map(fn ($row) => ['id' => $row->id, 'name' => $row->name], DB::select('SELECT * FROM app_campaign_partners()'));
    }

    /** Shared briefs a Brand can link an incentive to, with their Partner. */
    public function campaignChoices(string $org): array
    {
        return DB::table('campaigns as c')->join('partnerships as p', 'p.id', '=', 'c.partnership_id')->where('c.org_id', $org)
            ->where('p.status', 'active')->orderByDesc('c.shared_at')->orderBy('c.id')
            ->get(['c.id', 'c.title', 'c.partnership_id', 'p.creator_name as partner_name'])->map(fn ($row) => (array) $row)->all();
    }

    public function create(string $id, string $title, ?string $campaign): void
    {
        $this->settle(DB::selectOne('SELECT app_save_incentive(?::uuid, 0, ?::jsonb, false) AS status',
            [$id, json_encode(['title' => $title, 'campaign_id' => $campaign], JSON_THROW_ON_ERROR)])->status);
    }

    public function save(string $id, int $version, array $data, bool $share): void
    {
        $this->settle(DB::selectOne('SELECT app_save_incentive(?::uuid, ?::integer, ?::jsonb, ?::boolean) AS status',
            [$id, $version, json_encode($data, JSON_THROW_ON_ERROR), $share ? 'true' : 'false'])->status);
    }

    public function end(string $id, int $version): void
    {
        $this->settle(DB::selectOne('SELECT app_end_incentive(?::uuid, ?::integer) AS status', [$id, $version])->status);
    }

    public function decide(string $id, int $version, bool $approve, ?string $amount, ?string $reason): void
    {
        $this->settle(DB::selectOne('SELECT app_decide_incentive_award(?::uuid, ?::integer, ?::boolean, ?::bigint, ?::text) AS status',
            [$id, $version, $approve ? 'true' : 'false', $amount, $reason])->status, 'award');
    }

    public function fulfil(string $id, int $version, string $delivered, ?string $reference): void
    {
        $this->settle(DB::selectOne('SELECT app_fulfil_incentive_award(?::uuid, ?::integer, ?::date, ?::text) AS status',
            [$id, $version, $delivered, $reference])->status, 'award');
    }

    public function reverse(string $id, int $version, string $reason): void
    {
        $this->settle(DB::selectOne('SELECT app_reverse_incentive_award(?::uuid, ?::integer, ?::text) AS status', [$id, $version, $reason])->status, 'award');
    }

    // ----- Partner side -----------------------------------------------------------------------

    /** Offers shared with this workspace, each with its own cap use, room and claimable leads. */
    public function partnerOffers(string $org): array
    {
        $rows = DB::table('incentives as i')->join('incentive_partners as ip', 'ip.incentive_id', '=', 'i.id')
            ->join('partnerships as p', 'p.id', '=', 'ip.partnership_id')->where('ip.creator_org_id', $org)->where('i.state', '<>', 'draft')
            ->orderByRaw("(i.state = 'shared') DESC")->orderByDesc('i.shared_at')->orderBy('i.id')->limit(100)
            ->get(['i.*', 'p.id as partnership_id', 'p.brand_name', DB::raw('app_incentive_has_room(i.id) AS has_room'),
                DB::raw('(SELECT c.title FROM campaigns c WHERE c.id = i.campaign_id) AS campaign_title')]);

        return $rows->map(function ($row) use ($org) {
            $mine = DB::table('incentive_awards')->where('incentive_id', $row->id)->where('creator_org_id', $org)
                ->selectRaw("count(*) FILTER (WHERE state IN ('approved','fulfilled'))::int AS granted,
                    count(*) FILTER (WHERE state = 'pending')::int AS pending")->first();
            $candidates = $this->candidates($org, $row);
            $open = $row->state === 'shared' && today()->toDateString() >= $row->starts_on && today()->toDateString() <= $row->ends_on;

            return [...$this->terms($row), 'id' => $row->id, 'state' => $row->state, 'brand_name' => $row->brand_name,
                'partnership_id' => $row->partnership_id, 'campaign_id' => $row->campaign_id, 'campaign_title' => $row->campaign_title,
                'cap_per_partner' => $row->cap_per_partner, 'granted' => $mine->granted, 'pending' => $mine->pending,
                'has_room' => (bool) $row->has_room, 'open' => $open,
                'ready' => count(array_filter($candidates, fn ($lead) => $lead['eligible'])), 'candidates' => $candidates];
        })->all();
    }

    public function partnerFigures(array $offers, array $awards): array
    {
        return ['offers' => count(array_filter($offers, fn ($offer) => $offer['open'])),
            'review' => count(array_filter($awards, fn ($award) => $award['state'] === 'pending')),
            'approved' => count(array_filter($awards, fn ($award) => $award['state'] === 'approved')),
            'received' => count(array_filter($awards, fn ($award) => $award['state'] === 'fulfilled'))];
    }

    /** The workspace's own awards, newest first, with the leads each cites. */
    public function partnerAwards(string $org): array
    {
        $rows = DB::table('incentive_awards as a')->join('incentives as i', 'i.id', '=', 'a.incentive_id')->where('a.creator_org_id', $org)
            ->orderByDesc('a.claimed_at')->orderBy('a.id')->limit(200)
            ->get(['a.*', 'i.title as incentive_title', 'i.reward_type', 'i.currency', 'i.reward_text',
                DB::raw('(SELECT p.brand_name FROM partnerships p WHERE p.id = a.partnership_id) AS brand_name'),
                DB::raw('(SELECT count(*) FROM incentive_award_registrations ar WHERE ar.award_id = a.id)::int AS lead_count')]);
        $names = DB::table('incentive_award_registrations')->whereIn('award_id', $rows->pluck('id'))->orderBy('lead_name')
            ->get(['award_id', 'lead_name'])->groupBy('award_id');

        return $rows->map(fn ($row) => [...$this->awardRow($row), 'brand_name' => $row->brand_name,
            'leads' => ($names[$row->id] ?? collect())->pluck('lead_name')->all()])->all();
    }

    /** Campaign-linked offers and award states for a Partner's brief (D43/D44). */
    public function awardStatesForLeads(array $registrationIds): array
    {
        return DB::table('incentive_award_registrations as ar')->join('incentive_awards as a', 'a.id', '=', 'ar.award_id')
            ->whereIn('ar.registration_id', $registrationIds)->where('ar.released', false)
            ->pluck('a.state', 'ar.registration_id')->all();
    }

    public function claim(string $id, string $incentive, string $partnership, array $registrations, string $note): void
    {
        try {
            $status = DB::selectOne('SELECT app_claim_incentive_award(?::uuid, ?::uuid, ?::uuid, ?::uuid[], ?::text) AS status',
                [$id, $incentive, $partnership, '{'.implode(',', $registrations).'}', $note])->status;
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? '') === 'P0006') {
                throw ValidationException::withMessages(['registrations' => 'One of these leads already supports an award for this incentive. Choose another lead.']);
            }
            throw $error;
        }
        $messages = [
            'closed' => 'This incentive is not accepting claims. It has ended or is outside its dates.',
            'cap' => 'No more awards are available for you on this incentive.',
            'ineligible' => 'Choose leads that meet the rule. Refresh the page if a lead changed.',
            'unavailable' => 'This Brand is not available. The relationship may have ended or been paused.',
        ];
        if (isset($messages[$status])) {
            throw ValidationException::withMessages(['registrations' => $messages[$status]]);
        }
        $this->settle($status, 'award');
    }

    // ----- Shared -----------------------------------------------------------------------------

    private function offerRow(object $row): array
    {
        $audience = $row->state === 'draft'
            ? DB::table('partnerships')->whereIn('id', $this->uuidArray($row->audience))->orderByRaw('lower(creator_name)')->get(['id', 'creator_name'])
            : DB::table('incentive_partners as ip')->leftJoin('partnerships as p', 'p.id', '=', 'ip.partnership_id')
                ->where('ip.incentive_id', $row->id)->orderByRaw('lower(p.creator_name)')->get(['ip.partnership_id as id', 'p.creator_name']);
        $awards = DB::table('incentive_awards')->where('incentive_id', $row->id)->selectRaw("count(*) FILTER (WHERE state IN ('approved','fulfilled'))::int AS granted,
            count(*) FILTER (WHERE state = 'pending')::int AS pending")->first();

        return [...$this->terms($row), 'id' => $row->id, 'state' => $row->state, 'version' => $row->version,
            'campaign_id' => $row->campaign_id, 'campaign_title' => $row->campaign_title ?? null,
            'cap_per_partner' => $row->cap_per_partner, 'cap_total' => $row->cap_total,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'shared_at' => $row->shared_at ? Carbon::parse($row->shared_at)->toIso8601String() : null,
            'ended_at' => $row->ended_at ? Carbon::parse($row->ended_at)->toIso8601String() : null,
            // An ended relationship's Partner name is no longer readable; it stays counted in the audience.
            'audience' => $audience->map(fn ($partner) => ['id' => $partner->id, 'name' => $partner->creator_name])->all(),
            'granted' => $awards->granted, 'pending' => $awards->pending];
    }

    /** Terms both sides see. */
    private function terms(object $row): array
    {
        return ['title' => $row->title, 'terms' => $row->terms, 'reward_type' => $row->reward_type,
            'amount_minor' => $row->amount_minor === null ? null : (string) $row->amount_minor, 'currency' => $row->currency,
            'reward_text' => $row->reward_text, 'rule_stage' => $row->rule_stage, 'leads_per_award' => (int) $row->leads_per_award,
            'starts_on' => $row->starts_on, 'ends_on' => $row->ends_on];
    }

    private function awardRow(object $row): array
    {
        return ['id' => $row->id, 'incentive_id' => $row->incentive_id, 'incentive_title' => $row->incentive_title,
            'partnership_id' => $row->partnership_id, 'partner_name' => $row->partner_name ?? null, 'state' => $row->state,
            'reward_type' => $row->reward_type, 'currency' => $row->currency, 'reward_text' => $row->reward_text,
            'amount_minor' => $row->amount_minor === null ? null : (string) $row->amount_minor, 'amount_reason' => $row->amount_reason,
            'note' => $row->note, 'decision_reason' => $row->decision_reason, 'cancel_reason' => $row->cancel_reason,
            'claimed_at' => Carbon::parse($row->claimed_at)->toIso8601String(),
            'decided_at' => $row->decided_at ? Carbon::parse($row->decided_at)->toIso8601String() : null,
            'fulfilled_on' => $row->fulfilled_on, 'fulfilment_reference' => $row->fulfilment_reference,
            'lead_count' => (int) ($row->lead_count ?? 0), 'version' => $row->version];
    }

    /** Cited leads with their current shared state where still visible, and a live rule check. */
    private function citedLeads(string $award, string $rule, ?string $campaign): array
    {
        return DB::table('incentive_award_registrations as ar')->leftJoin('lead_registrations as r', 'r.id', '=', 'ar.registration_id')
            ->leftJoin('campaigns as c', 'c.id', '=', 'r.campaign_id')->where('ar.award_id', $award)->orderBy('ar.lead_name')
            ->get(['ar.registration_id', 'ar.lead_name', 'ar.stage_at_claim', 'r.id as visible', 'r.display_name', 'r.stage', 'r.registered_at',
                'r.campaign_id', 'r.campaign_linked_at', 'r.campaign_confirmed_at', 'c.title as campaign_title'])
            ->map(function ($row) use ($rule, $campaign) {
                $stage = $row->visible ? $row->stage : $row->stage_at_claim;
                $meets = $this->meets($stage, $rule) && ($campaign === null || $row->campaign_id === $campaign);

                return ['id' => $row->registration_id, 'name' => $row->visible ? $row->display_name : $row->lead_name,
                    'visible' => $row->visible !== null, 'stage' => $stage, 'stage_at_claim' => $row->stage_at_claim,
                    'registered_at' => $row->registered_at ? Carbon::parse($row->registered_at)->toIso8601String() : null,
                    'campaign_title' => $row->campaign_title,
                    'linked_at' => $row->campaign_linked_at ? Carbon::parse($row->campaign_linked_at)->toIso8601String() : null,
                    'confirmed' => $row->campaign_confirmed_at !== null, 'meets' => $meets];
            })->all();
    }

    /** The workspace's active registrations on the offer's relationship, each with a plain eligibility reason. */
    private function candidates(string $org, object $offer): array
    {
        $used = DB::table('incentive_award_registrations')->where('incentive_id', $offer->id)->where('released', false)->pluck('registration_id')->flip();

        return DB::table('lead_registrations as r')->leftJoin('campaigns as c', 'c.id', '=', 'r.campaign_id')
            ->where('r.creator_org_id', $org)->where('r.partnership_id', $offer->partnership_id)->where('r.state', 'active')
            ->orderByDesc('r.registered_at')->orderBy('r.id')->limit(200)
            ->get(['r.id', 'r.display_name', 'r.stage', 'r.campaign_id', 'c.title as campaign_title'])
            ->map(function ($row) use ($offer, $used) {
                $reason = match (true) {
                    isset($used[$row->id]) => 'used',
                    ! $this->meets($row->stage, $offer->rule_stage) => 'stage',
                    $offer->campaign_id !== null && $row->campaign_id !== $offer->campaign_id => 'campaign',
                    default => null,
                };

                return ['id' => $row->id, 'name' => $row->display_name, 'stage' => $row->stage, 'campaign_title' => $row->campaign_title,
                    'eligible' => $reason === null, 'reason' => $reason];
            })->sortBy(fn ($lead) => $lead['eligible'] ? 0 : 1)->values()->all();
    }

    private function meets(string $stage, string $rule): bool
    {
        $at = array_search($stage, self::STAGES, true);

        return $at !== false && $at >= array_search($rule, self::STAGES, true);
    }

    /** @return list<string> */
    private function uuidArray(?string $value): array
    {
        return $value === null || $value === '{}' ? [] : explode(',', trim($value, '{}'));
    }

    private function settle(string $status, string $subject = 'incentive'): void
    {
        abort_if($status === 'denied', 403);
        abort_if($status === 'missing', 404);
        $messages = [
            'stale' => ['version', 'This '.$subject.' changed in another session. Reload the page before trying again.'],
            'campaign' => ['campaign_id', 'Choose a campaign brief you have shared, or leave the campaign empty.'],
            'audience' => ['audience', 'Choose at least one Partner.'],
            'unavailable' => ['audience', 'One of these Partners is no longer available. Choose active Partners.'],
            'reason' => ['reason', 'Give a reason. It is shown to the Partner.'],
            'cap' => ['decision', 'Approving this award would exceed a cap on this incentive.'],
        ];
        if (isset($messages[$status])) {
            throw ValidationException::withMessages([$messages[$status][0] => $messages[$status][1]])->status($status === 'stale' ? 409 : 422);
        }
    }
}
