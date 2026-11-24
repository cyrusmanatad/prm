<?php

namespace App\Services;

use App\Enums\PipelineStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only projections for the Brand, Partner and Professional Home pages.
 *
 * Every query runs inside the request's locked tenant context, so row security still decides what the actor can
 * see; Home only reuses tables and services the existing tools already read for this workspace and never widens
 * them. Counts state what they measure (leads, registrations or partnerships), stored stages are never folded, money
 * stays in minor units per currency, and figures the actor may not see are null rather than zero.
 */
class PortalHome
{
    public const RECENT = 5;

    public const ACTIVITY = 10;

    /** Logged conversations keep their text in the lead drawer; Home names only the kind. */
    private const CONVERSATION_KINDS = ['note', 'call', 'email', 'chat', 'reply'];

    public function __construct(private Crm $crm, private ProfessionalHub $hub, private LeadRegistrations $leads) {}

    /** Partner and Professional (creator) workspaces: the workspace's own leads, reminders and onboarding. */
    public function creator(string $org, bool $professional): array
    {
        $now = DB::selectOne('SELECT clock_timestamp() AS moment')->moment;
        $rows = DB::table('pipeline_entries')->where('org_id', $org)
            ->selectRaw('stage, currency, count(*)::int AS total, sum(value_minor)::text AS amount_minor')
            ->groupBy('stage', 'currency')->orderBy('currency')->get();
        $stages = array_map(function (array $option) use ($rows) {
            $matching = $rows->where('stage', $option['value']);

            return [...$option, 'count' => (int) $matching->sum('total'),
                'totals' => $matching->map(fn ($row) => ['currency' => $row->currency, 'amount_minor' => (string) $row->amount_minor])->values()->all()];
        }, PipelineStage::options());
        // The open value matches the leads board: every stage except Lost, per currency, never converted.
        $open = DB::table('pipeline_entries')->where('org_id', $org)->where('stage', '!=', PipelineStage::Lost->value)
            ->selectRaw('currency, sum(value_minor)::text AS amount_minor')->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'amount_minor' => (string) $row->amount_minor])->all();
        $reminders = $this->crm->summary($org);
        $upNext = $this->hub->upNext($org);
        $onboarding = DB::table('onboarding_requests')->where('org_id', $org)->where('status', 'submitted')->count();

        return [
            'clock' => Carbon::parse($now)->toIso8601String(),
            'tasks' => [
                'reminders' => $upNext['reminders'], 'reminderTotal' => $upNext['reminderTotal'],
                'onboarding' => $upNext['onboarding'], 'onboardingTotal' => $upNext['onboardingTotal'],
                'leadsDue' => DB::table('pipeline_entries')->where('org_id', $org)->whereNotNull('next_action_due')
                    ->where('next_action_due', '<=', $now)->count(),
            ],
            'metrics' => [
                'openLeads' => array_sum(array_map(fn ($stage) => $stage['value'] === PipelineStage::Lost->value ? 0 : $stage['count'], $stages)),
                'openValue' => $open,
                'remindersDue' => $reminders['overdue'] + $reminders['dueWeek'],
                'onboardingReview' => $onboarding,
                // Brand partnerships are a Partner figure; Professional Home leaves them to Brand sharing.
                'partnerships' => $professional ? null : DB::table('partnerships')->where('creator_org_id', $org)->where('status', 'active')->count(),
            ],
            'stages' => $stages,
            'recent' => DB::table('pipeline_entries')->where('org_id', $org)->orderByDesc('updated_at')->orderByDesc('id')->limit(self::RECENT)
                ->get(['id', 'brand_name', 'stage', 'currency', 'value_minor', 'next_action_due', 'updated_at'])
                ->map(fn ($row) => ['id' => $row->id, 'name' => $row->brand_name, 'stage' => $row->stage, 'currency' => $row->currency,
                    'value_minor' => (string) $row->value_minor, 'next_action_due' => $this->iso($row->next_action_due),
                    'updated_at' => $this->iso($row->updated_at)])->all(),
            'activity' => DB::table('pipeline_activities AS a')->where('a.org_id', $org)
                ->join('pipeline_entries AS p', fn ($join) => $join->on('p.org_id', '=', 'a.org_id')->on('p.id', '=', 'a.pipeline_entry_id'))
                ->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit(self::ACTIVITY)
                ->get(['a.id', 'a.kind', 'a.body', 'a.occurred_at', 'p.id AS entry_id', 'p.brand_name'])
                ->map(fn ($row) => ['id' => $row->id, 'kind' => $row->kind, 'entry_id' => $row->entry_id, 'name' => $row->brand_name,
                    'body' => in_array($row->kind, self::CONVERSATION_KINDS, true) ? '' : (string) $row->body,
                    'occurred_at' => $this->iso($row->occurred_at)])->all(),
            'profilePublished' => DB::table('media_kits')->where('org_id', $org)->where('published', true)->whereNotNull('consented_at')->exists(),
        ];
    }

    /** Brand workspaces: relationships and explicitly registered leads only; private Partner pipelines are never read. */
    public function brand(string $org, bool $manage): array
    {
        $now = $this->leads->clock();
        $metrics = $this->leads->metrics($org);
        $summary = $this->leads->summary($org, $now);
        $facets = $this->leads->facets($org, [], $now);

        return [
            'clock' => Carbon::parse($now)->toIso8601String(),
            'tasks' => [
                // Invitations are readable by owners and admins only; other roles see no figure rather than zero.
                'pendingInvitations' => $manage ? DB::table('partner_invitations')->where('brand_org_id', $org)
                    ->where('status', 'pending')->where('expires_at', '>', $now)->count() : null,
                'updatedWeek' => $summary['updatedWeek'],
                'registeredRecent' => $summary['recent'],
            ],
            'metrics' => [
                'activePartners' => $metrics['activePartners'],
                'leadsRegistered' => $metrics['leadsRegistered'],
                'registeredRecent' => $summary['recent'],
                'partnersWithLeads' => $summary['partners'],
            ],
            'stages' => array_map(fn (array $option) => [...$option, 'count' => (int) ($facets['stages'][$option['value']] ?? 0), 'totals' => []],
                PipelineStage::options()),
            'recent' => array_map(fn (array $row) => ['id' => $row['id'], 'name' => $row['display_name'], 'stage' => $row['stage'],
                'partner_name' => $row['partner_name'], 'registered_at' => $row['registered_at'], 'updated_at' => $row['shared_updated_at']],
                $this->leads->listing($org, [], 1, $now, self::RECENT)['rows']),
            'activity' => $this->leads->recentActivity($org, self::ACTIVITY),
            'canManage' => $manage,
        ];
    }

    private function iso(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
