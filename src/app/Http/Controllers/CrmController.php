<?php

namespace App\Http\Controllers;

use App\Services\Crm;
use App\Services\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CrmController extends Controller
{
    public function index(Request $request, Crm $crm, Pipeline $pipeline): mixed
    {
        if ($request->is('crm') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect('/partner/contacts'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        $filters = $request->validate(['q' => ['nullable', 'string', 'max:160'], 'state' => ['nullable', 'in:pending,due,completed,all'],
            'contacts_cursor' => ['nullable', 'uuid'], 'reminders_cursor' => ['nullable', 'uuid'], 'entry' => ['nullable', 'uuid']]);
        $org = $request->session()->get('active_org_id');
        $entry = empty($filters['entry']) ? null : $pipeline->detail($org, $filters['entry']);
        $payload = ['contacts' => $crm->listing($org, 'contacts', $filters), 'reminders' => $crm->listing($org, 'reminders', $filters),
            'entry' => $entry ? ['id' => $entry['id'], 'brand_name' => $entry['brand_name'], 'version' => $entry['version']] : null,
            'linked' => $entry ? DB::table('pipeline_contacts')->join('brand_contacts', 'brand_contacts.id', '=', 'pipeline_contacts.contact_id')
                ->where('pipeline_contacts.org_id', $org)->where('pipeline_entry_id', $entry['id'])->get(['brand_contacts.id', 'brand_contacts.name'])->all() : [],
            'workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')],
            'canWrite' => $pipeline->canWrite((int) $request->user()->id, $org), 'filters' => ['q' => $filters['q'] ?? '', 'state' => $filters['state'] ?? 'pending']];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('Crm', $payload);
    }

    public function store(Request $request, Crm $crm, string $kind): mixed
    {
        $id = $crm->save((int) $request->user()->id, $request->session()->get('active_org_id'), $kind, $this->details($request, $kind));

        return $this->completed($request, ['id' => $id, 'message' => 'Record created.'], $this->destination($request), 201);
    }

    public function update(Request $request, Crm $crm, string $kind, string $record): mixed
    {
        $crm->save((int) $request->user()->id, $request->session()->get('active_org_id'), $kind, $this->details($request, $kind), $record, $this->version($request));

        return $this->completed($request, ['message' => 'Record saved.'], $this->destination($request));
    }

    public function destroy(Request $request, Crm $crm, string $kind, string $record): mixed
    {
        $crm->delete((int) $request->user()->id, $request->session()->get('active_org_id'), $kind, $record, $this->version($request));

        return $this->completed($request, ['message' => 'Record deleted.'], $this->destination($request));
    }

    public function command(Request $request, Crm $crm, string $record, string $action): mixed
    {
        $due = null;
        if ($action === 'snooze') {
            $d = $request->validate(['due_at' => ['required', 'date', 'after:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/']]);
            $due = Carbon::parse($d['due_at'])->utc()->toIso8601String();
        }
        $crm->command((int) $request->user()->id, $request->session()->get('active_org_id'), $record, $this->version($request), $action, $due);

        return $this->completed($request, ['message' => 'Reminder '.$action.' saved.'], $this->destination($request));
    }

    public function link(Request $request, Crm $crm, string $entry): mixed
    {
        $contact = $request->validate(['contact_id' => ['required', 'uuid']])['contact_id'];
        $crm->link((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, $contact, $this->version($request), $request->isMethod('post'));

        return $this->completed($request, ['message' => 'Contact links saved.'], '/partner/contacts?entry='.$entry);
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

    private function destination(Request $request): string
    {
        $entry = $request->validate(['entry' => ['nullable', 'uuid']])['entry'] ?? null;

        return '/partner/contacts'.($entry ? '?entry='.$entry : '');
    }
}
