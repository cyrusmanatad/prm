<?php

namespace App\Http\Controllers;

use App\Enums\PipelineStage;
use App\Services\Incentives;
use App\Services\LeadRegistrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Brand incentives (D44): Brand offers and award decisions, Partner offers and claims. */
class IncentiveController extends Controller
{
    public function __construct(private Incentives $incentives) {}

    public function index(Request $request, string $experience, ?string $incentive = null): mixed
    {
        $workspace = $this->workspace($request, $experience);
        $data = ['workspace' => $workspace, 'experience' => $experience, 'stages' => PipelineStage::options()];
        if ($experience === 'partner') {
            $offers = $this->incentives->partnerOffers($workspace->id);
            $awards = $this->incentives->partnerAwards($workspace->id);
            $data += ['canWrite' => app(LeadRegistrations::class)->canManage($workspace->id), 'offers' => $offers, 'awards' => $awards,
                'figures' => $this->incentives->partnerFigures($offers, $awards)];
        } else {
            $query = $request->validate([
                'tab' => ['sometimes', Rule::in(['offers', 'awards'])], 'status' => ['sometimes', Rule::in(['all', 'draft', 'shared', 'ended'])],
                'awards' => ['sometimes', Rule::in(['pending', 'approved', 'fulfilled', 'closed', 'all'])], 'award' => ['sometimes', 'uuid'],
            ]);
            $canWrite = app(LeadRegistrations::class)->canManage($workspace->id);
            $data += ['canWrite' => $canWrite, 'selected' => null, 'award' => null, 'partners' => [], 'campaigns' => []];
            if ($incentive !== null) {
                abort_unless($data['selected'] = $this->incentives->brandIncentive($workspace->id, $incentive), 404);
                if ($canWrite && $data['selected']['state'] === 'draft') {
                    $data['partners'] = $this->incentives->partnerChoices();
                    $data['campaigns'] = $this->incentives->campaignChoices($workspace->id);
                }
            } else {
                // `offerStatus`, not `status`: the shared `status` prop carries flash messages.
                $data += ['tab' => $query['tab'] ?? 'offers', 'offerStatus' => $query['status'] ?? 'all', 'awardState' => $query['awards'] ?? 'pending',
                    'figures' => $this->incentives->brandFigures($workspace->id),
                    'offers' => $this->incentives->offers($workspace->id, $query['status'] ?? 'all'),
                    'awardList' => $this->incentives->brandAwards($workspace->id, ['state' => $query['awards'] ?? 'pending'])];
            }
            if (isset($query['award'])) {
                abort_unless($data['award'] = $this->incentives->brandAward($workspace->id, $query['award']), 404);
            }
        }

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($data) : Inertia::render('Incentives', $data);
    }

    public function store(Request $request): mixed
    {
        $this->manager($request, 'brand');
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'campaign_id' => ['nullable', 'uuid']]);
        $id = (string) Str::uuid();
        $this->incentives->create($id, trim($data['title']), $data['campaign_id'] ?? null);

        return $request->expectsJson() ? response()->json(['id' => $id], 201) : redirect('/brand/incentives/'.$id);
    }

    public function update(Request $request, string $incentive, string $action): mixed
    {
        $this->manager($request, 'brand');
        $share = $action === 'share';
        $need = $share ? 'required' : 'nullable';
        $data = $request->validate([
            'version' => ['required', 'integer', 'between:1,2147483646'],
            'title' => ['required', 'string', 'max:160'],
            'terms' => [$need, 'string', 'max:4000'],
            'reward_type' => ['required', Rule::in(['cash', 'non_cash', 'percent'])],
            'amount_minor' => [$share ? 'required_if:reward_type,cash' : 'nullable', 'nullable', 'integer', 'between:1,99999999999'],
            'currency' => [$share ? 'required_if:reward_type,cash' : 'nullable', 'nullable', 'regex:/^[A-Z]{3}$/D'],
            'reward_text' => [$share ? 'required_unless:reward_type,cash' : 'nullable', 'nullable', 'string', 'max:200'],
            'rule_stage' => ['required', Rule::in(['pitched', 'negotiating', 'onboarded', 'active', 'repeat-client'])],
            'leads_per_award' => ['required', 'integer', 'between:1,20'],
            'campaign_id' => ['nullable', 'uuid'],
            'starts_on' => [$need, 'date_format:Y-m-d'],
            'ends_on' => [$need, 'date_format:Y-m-d', 'after_or_equal:starts_on', ...($share ? ['after_or_equal:today'] : [])],
            'cap_per_partner' => ['nullable', 'integer', 'between:1,10000', ...($request->filled('cap_total') ? ['lte:cap_total'] : [])],
            'cap_total' => ['nullable', 'integer', 'between:1,100000'],
            'audience' => ['nullable', 'array', 'max:500'], 'audience.*' => ['uuid'],
        ], [
            'ends_on.after_or_equal' => 'The end date must be on or after the start date and not in the past.',
            'cap_per_partner.lte' => 'Awards per Partner cannot be more than awards in total.',
            'amount_minor.required_if' => 'Enter the reward amount.', 'reward_text.required_unless' => 'Describe the reward.',
        ]);
        $this->incentives->save($incentive, (int) $data['version'], array_diff_key($data, ['version' => 0]), $share);

        return $request->expectsJson() ? response()->json(['id' => $incentive])
            : redirect('/brand/incentives/'.$incentive)->with('status', $share ? 'Incentive shared.' : 'Draft saved.');
    }

    public function end(Request $request, string $incentive): mixed
    {
        $this->manager($request, 'brand');
        $version = $request->validate(['version' => ['required', 'integer', 'between:1,2147483646']])['version'];
        $this->incentives->end($incentive, (int) $version);

        return $request->expectsJson() ? response()->json(['id' => $incentive]) : redirect('/brand/incentives/'.$incentive)->with('status', 'Incentive ended.');
    }

    public function decide(Request $request, string $award, string $action): mixed
    {
        $this->manager($request, 'brand');
        $data = $request->validate([
            'version' => ['required', 'integer', 'between:1,2147483646'],
            'reason' => [in_array($action, ['reject', 'reverse'], true) ? 'required' : 'nullable', 'nullable', 'string', 'max:500'],
            'amount_minor' => ['nullable', 'integer', 'between:1,99999999999'],
            'fulfilled_on' => [$action === 'fulfil' ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:200'],
        ], ['reason.required' => 'Give a reason. It is shown to the Partner.', 'fulfilled_on.before_or_equal' => 'The delivery date cannot be in the future.']);
        $version = (int) $data['version'];
        match ($action) {
            'approve', 'reject' => $this->incentives->decide($award, $version, $action === 'approve',
                isset($data['amount_minor']) ? (string) $data['amount_minor'] : null, $data['reason'] ?? null),
            'fulfil' => $this->incentives->fulfil($award, $version, $data['fulfilled_on'], $data['reference'] ?? null),
            'reverse' => $this->incentives->reverse($award, $version, $data['reason']),
        };
        $message = ['approve' => 'Award approved.', 'reject' => 'Award rejected.', 'fulfil' => 'Award marked fulfilled.',
            'reverse' => 'Approval reversed. The award is back in review.'][$action];

        return $request->expectsJson() ? response()->json(['id' => $award]) : back()->with('status', $message);
    }

    public function claim(Request $request, string $incentive): mixed
    {
        $this->manager($request, 'partner');
        $data = $request->validate([
            'partnership' => ['required', 'uuid'], 'registrations' => ['required', 'array', 'min:1', 'max:20'], 'registrations.*' => ['uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['registrations.required' => 'Choose the leads that meet the rule.']);
        $id = (string) Str::uuid();
        $this->incentives->claim($id, $incentive, $data['partnership'], array_values(array_unique($data['registrations'])), trim($data['note'] ?? ''));

        return $request->expectsJson() ? response()->json(['id' => $id], 201) : back()->with('status', 'Claim sent to the Brand for review.');
    }

    private function manager(Request $request, string $experience): object
    {
        $workspace = $this->workspace($request, $experience);
        abort_unless(app(LeadRegistrations::class)->canManage($workspace->id), 403);

        return $workspace;
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
