<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Hub figures for the active Professional workspace.
 *
 * Every query runs inside the request's tenant context, so row security still decides
 * what the actor can see. Finance stays owner-only and follow-up drafts stay actor-scoped
 * here as well, so non-owners receive null rather than a misleading zero.
 */
class ProfessionalHub
{
    public const UP_NEXT_LIMIT = 6;

    public function __construct(private Crm $crm) {}

    public function summary(string $org, int $actor, bool $owner): array
    {
        $stages = DB::table('pipeline_entries')->where('org_id', $org)->where('stage', '<>', 'lost')
            ->groupBy('stage')->selectRaw('stage, count(*) AS total')->pluck('total', 'stage')->map(fn ($n) => (int) $n)->all();
        $reminders = $this->crm->summary($org);
        $onboarding = DB::table('onboarding_requests')->where('org_id', $org)->whereIn('status', ['submitted', 'correction'])
            ->groupBy('status')->selectRaw('status, count(*) AS total')->pluck('total', 'status');
        $today = Carbon::now('UTC')->startOfDay();
        $invoices = $owner ? DB::table('invoice_drafts')->where('org_id', $org)->where('status', 'draft')->selectRaw(
            'count(*) AS drafts, count(*) FILTER (WHERE due_date >= ? AND due_date < ?) AS due_week',
            [$today->toDateString(), $today->copy()->addDays(7)->toDateString()],
        )->first() : null;

        return [
            'leads' => ['open' => array_sum($stages), 'stages' => $stages],
            'reminders' => ['overdue' => $reminders['overdue'], 'dueDay' => $reminders['dueDay'], 'dueWeek' => $reminders['dueWeek']],
            'onboarding' => ['submitted' => (int) ($onboarding['submitted'] ?? 0), 'correction' => (int) ($onboarding['correction'] ?? 0)],
            'invoices' => $invoices ? ['drafts' => (int) $invoices->drafts, 'dueWeek' => (int) $invoices->due_week] : null,
            'payments' => $owner ? DB::table('manual_payments')->where('org_id', $org)->count() : null,
            'templates' => DB::table('outreach_templates')->where('org_id', $org)->count(),
            'followUps' => DB::table('follow_up_drafts')->where('org_id', $org)->where('actor_id', $actor)->where('state', 'draft')->count(),
            'profilePublished' => DB::table('media_kits')->where('org_id', $org)->where('published', true)->whereNotNull('consented_at')->exists(),
        ];
    }

    /** Open reminders overdue or due within a day, and onboarding submissions awaiting review. */
    public function upNext(string $org): array
    {
        $until = now()->addDay();
        $reminders = DB::table('reminders AS r')->where('r.org_id', $org)->whereNull('r.completed_at')->where('r.due_at', '<', $until)
            ->leftJoin('pipeline_entries AS p', fn ($join) => $join->on('p.org_id', '=', 'r.org_id')->on('p.id', '=', 'r.pipeline_entry_id'));
        $onboarding = DB::table('onboarding_requests AS o')->where('o.org_id', $org)->where('o.status', 'submitted')
            ->join('pipeline_entries AS p', fn ($join) => $join->on('p.org_id', '=', 'o.org_id')->on('p.id', '=', 'o.pipeline_entry_id'));

        return [
            'reminderTotal' => (clone $reminders)->count(),
            'onboardingTotal' => (clone $onboarding)->count(),
            'reminders' => $reminders->orderBy('r.due_at')->orderBy('r.id')->limit(self::UP_NEXT_LIMIT)
                ->get(['r.id', 'r.title', 'r.due_at', 'p.brand_name AS lead'])
                ->map(fn ($row) => ['id' => $row->id, 'title' => $row->title, 'due_at' => Carbon::parse($row->due_at)->toIso8601String(), 'lead' => $row->lead])->all(),
            'onboarding' => $onboarding->orderByRaw('submitted_at NULLS LAST')->orderBy('o.id')->limit(self::UP_NEXT_LIMIT)
                ->get(['o.id', 'o.label', 'p.brand_name AS lead', DB::raw('(SELECT max(s.consent_at) FROM onboarding_submissions s WHERE s.org_id = o.org_id AND s.request_id = o.id) AS submitted_at')])
                ->map(fn ($row) => ['id' => $row->id, 'label' => $row->label, 'lead' => $row->lead, 'submitted_at' => $row->submitted_at ? Carbon::parse($row->submitted_at)->toIso8601String() : null])->all(),
        ];
    }
}
