<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Crm;
use App\Services\Pipeline;
use App\Support\CsvDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CrmController extends Controller
{
    private const FILTER_KEYS = ['q', 'state', 'sort', 'linked', 'per_page', 'contacts_page', 'reminders_page'];

    public function index(Request $request, Crm $crm, Pipeline $pipeline): mixed
    {
        if ($request->is('crm') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect('/partner/contacts'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        $filters = $request->validate([...$this->filterRules(), 'entry' => ['nullable', 'uuid']]);
        $org = $request->session()->get('active_org_id');
        $entry = empty($filters['entry']) ? null : $pipeline->detail($org, $filters['entry']);
        $payload = ['contacts' => $crm->listing($org, 'contacts', $filters), 'reminders' => $crm->listing($org, 'reminders', $filters),
            'summary' => $crm->summary($org),
            'entry' => $entry ? ['id' => $entry['id'], 'brand_name' => $entry['brand_name'], 'version' => $entry['version']] : null,
            'linked' => $entry ? DB::table('pipeline_contacts')->join('brand_contacts', 'brand_contacts.id', '=', 'pipeline_contacts.contact_id')
                ->where('pipeline_contacts.org_id', $org)->where('pipeline_entry_id', $entry['id'])->orderBy('brand_contacts.name')->get(['brand_contacts.id', 'brand_contacts.name'])->all() : [],
            'workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')],
            'canWrite' => $pipeline->canWrite((int) $request->user()->id, $org),
            'filters' => ['q' => $filters['q'] ?? '', 'state' => $filters['state'] ?? 'pending', 'sort' => $filters['sort'] ?? '', 'linked' => $filters['linked'] ?? '', 'per_page' => (string) ($filters['per_page'] ?? '')]];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('Crm', $payload);
    }

    public function store(Request $request, Crm $crm, string $kind): mixed
    {
        $destination = $this->destination($request);
        $id = $crm->save((int) $request->user()->id, $request->session()->get('active_org_id'), $kind, $this->details($request, $kind));

        return $this->completed($request, ['id' => $id, 'message' => $this->recordLabel($kind).' created'], $destination, 201);
    }

    public function update(Request $request, Crm $crm, string $kind, string $record): mixed
    {
        $destination = $this->destination($request);
        $crm->save((int) $request->user()->id, $request->session()->get('active_org_id'), $kind, $this->details($request, $kind), $record, $this->version($request));

        return $this->completed($request, ['message' => $this->recordLabel($kind).' saved'], $destination);
    }

    public function destroy(Request $request, Crm $crm, string $kind, string $record): mixed
    {
        $destination = $this->destination($request);
        $crm->delete((int) $request->user()->id, $request->session()->get('active_org_id'), $kind, $record, $this->version($request));

        return $this->completed($request, ['message' => $this->recordLabel($kind).' deleted'], $destination);
    }

    public function command(Request $request, Crm $crm, string $record, string $action): mixed
    {
        $destination = $this->destination($request);
        $due = null;
        if ($action === 'snooze') {
            $d = $request->validate(['due_at' => ['required', 'date', 'after:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/']]);
            $due = Carbon::parse($d['due_at'])->utc()->toIso8601String();
        }
        $crm->command((int) $request->user()->id, $request->session()->get('active_org_id'), $record, $this->version($request), $action, $due);

        $message = match ($action) {
            'snooze' => 'Reminder snoozed until '.Carbon::parse((string) $due)->format('M j'),
            'complete' => 'Reminder completed',
            'reopen' => 'Reminder reopened',
            default => 'Reminder updated',
        };

        return $this->completed($request, ['message' => $message], $destination);
    }

    public function link(Request $request, Crm $crm, string $entry): mixed
    {
        $destination = $this->destination($request, $entry);
        $contact = $request->validate(['contact_id' => ['required', 'uuid']])['contact_id'];
        $crm->link((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, $contact, $this->version($request), $request->isMethod('post'));

        return $this->completed($request, ['message' => $request->isMethod('post') ? 'Contact linked' : 'Contact unlinked'], $destination);
    }

    public function bulk(Request $request, Crm $crm): mixed
    {
        $destination = $this->destination($request);
        $data = $request->validate([
            'action' => ['required', 'in:delete,link'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'uuid', 'distinct'],
            'items.*.version' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'link_entry' => ['required_if:action,link', 'nullable', 'uuid'],
            'entry_version' => ['required_if:action,link', 'nullable', 'integer', 'min:1', 'max:2147483646'],
        ]);
        $count = $crm->bulk((int) $request->user()->id, $request->session()->get('active_org_id'), $data['action'], $data['items'],
            $data['link_entry'] ?? null, isset($data['entry_version']) ? (int) $data['entry_version'] : null);
        $noun = $count === 1 ? 'contact' : 'contacts';

        return $this->completed($request, ['count' => $count, 'message' => $data['action'] === 'link' ? "{$count} {$noun} linked" : "{$count} {$noun} deleted"], $destination);
    }

    /** CSV of the filtered list, or of selected contacts, for anyone who can read the workspace CRM. */
    public function export(Request $request, Crm $crm, Audit $audit, string $resource): mixed
    {
        $filters = $request->validate([...$this->filterRules(), 'ids' => ['sometimes', 'array', 'max:100'], 'ids.*' => ['uuid']]);
        $org = $request->session()->get('active_org_id');
        if ($resource === 'contacts') {
            $header = ['id', 'name', 'email', 'company', 'notes', 'linked_leads'];
            $rows = $crm->contactQuery($org, $filters, $filters['ids'] ?? null)->get()
                ->map(fn ($row) => [$row->id, $row->name, $row->email, $row->company, $row->notes, $row->lead_count]);
        } else {
            $header = ['id', 'title', 'due_at', 'completed_at', 'lead'];
            $rows = $crm->reminderQuery($org, $filters)->get()->map(fn ($row) => [$row->id, $row->title,
                Carbon::parse($row->due_at)->utc()->toIso8601String(), $row->completed_at ? Carbon::parse($row->completed_at)->utc()->toIso8601String() : '', $row->lead_name]);
        }
        $audit->record((int) $request->user()->id, $org, $resource.'.exported', $org);

        return CsvDownload::make($resource.'.csv', $header, $rows);
    }

    /** List filters shared by the page, its exports and the state a mutation returns to. */
    private function filterRules(string $prefix = ''): array
    {
        return [
            $prefix.'q' => ['nullable', 'string', 'max:160'],
            $prefix.'state' => ['nullable', 'in:pending,due,completed,all'],
            $prefix.'sort' => ['nullable', 'in:'.implode(',', Crm::CONTACT_SORTS)],
            $prefix.'linked' => ['nullable', 'in:linked,unlinked'],
            $prefix.'per_page' => ['nullable', 'integer', 'in:'.implode(',', Crm::PAGE_SIZES)],
            $prefix.'contacts_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            $prefix.'reminders_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    private function details(Request $request, string $kind): array
    {
        if ($kind === 'contacts') {
            $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:255'], 'company' => ['nullable', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:5000']]);

            return ['name' => $d['name'], 'email' => $d['email'] ?? null, 'company' => $d['company'] ?? '', 'notes' => $d['notes'] ?? ''];
        }
        $d = $request->validate(['title' => ['required', 'string', 'max:240'], 'due_at' => ['required', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'], 'pipeline_entry_id' => ['nullable', 'uuid']]);

        return ['title' => $d['title'], 'due_at' => Carbon::parse($d['due_at'])->utc()->toIso8601String(), 'pipeline_entry_id' => $d['pipeline_entry_id'] ?? null];
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']])['version'];
    }

    private function recordLabel(string $kind): string
    {
        return $kind === 'contacts' ? 'Contact' : 'Reminder';
    }

    private function destination(Request $request, ?string $linkedEntry = null): string
    {
        $data = $request->validate([
            'entry' => ['nullable', 'uuid'],
            'crm_filters' => ['sometimes', 'array:'.implode(',', self::FILTER_KEYS)],
            ...$this->filterRules('crm_filters.'),
        ]);
        $filters = $data['crm_filters'] ?? [];
        $context = array_filter([
            ...array_map(fn ($key) => $filters[$key] ?? '', array_combine(self::FILTER_KEYS, self::FILTER_KEYS)),
            'entry' => $linkedEntry ?? $data['entry'] ?? '',
        ], fn ($value) => $value !== '' && $value !== null);

        return '/partner/contacts'.($context ? '?'.http_build_query($context) : '');
    }
}
