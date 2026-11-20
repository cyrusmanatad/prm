<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Methods run inside WorkspaceContext, including membership locks. */
class Crm
{
    public const TABLES = ['contacts' => 'brand_contacts', 'reminders' => 'reminders'];

    public function __construct(private Pipeline $pipeline, private Audit $audit) {}

    public const PAGE_SIZES = [10, 25, 50];

    public const CONTACT_SORTS = ['recent', 'name_asc', 'name_desc', 'company_asc'];

    public const REMINDER_PAGE_SIZE = 25;

    /** A numbered page of contacts or reminders with the matching total. Out-of-range pages clamp to the last page. */
    public function listing(string $org, string $kind, array $filters): array
    {
        $query = $kind === 'contacts' ? $this->contactQuery($org, $filters) : $this->reminderQuery($org, $filters);
        $perPage = $kind === 'contacts' && in_array((int) ($filters['per_page'] ?? 0), self::PAGE_SIZES, true)
            ? (int) $filters['per_page'] : self::REMINDER_PAGE_SIZE;
        $total = (clone $query)->count();
        $page = min(max(1, (int) ($filters[$kind.'_page'] ?? 1)), max(1, (int) ceil($total / $perPage)));
        $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(function ($row) use ($kind) {
            $item = (array) $row;
            if ($kind === 'contacts') {
                $item['lead_count'] = (int) $row->lead_count;
            } else {
                $item['due_at'] = Carbon::parse($row->due_at)->toIso8601String();
                $item['completed_at'] = $row->completed_at ? Carbon::parse($row->completed_at)->toIso8601String() : null;
            }

            return $item;
        })->all();

        return ['items' => $items, 'page' => $page, 'perPage' => $perPage, 'total' => $total];
    }

    /** Whole-workspace figures that never depend on the visible page or its filters. */
    public function summary(string $org): array
    {
        $now = now();
        $reminders = DB::table('reminders')->where('org_id', $org)->whereNull('completed_at')->selectRaw(
            'count(*) FILTER (WHERE due_at < ?) AS overdue, count(*) FILTER (WHERE due_at >= ? AND due_at < ?) AS day, count(*) FILTER (WHERE due_at >= ? AND due_at < ?) AS week',
            [$now, $now, $now->copy()->addDay(), $now, $now->copy()->addDays(7)],
        )->first();

        return [
            'contacts' => DB::table('brand_contacts')->where('org_id', $org)->count(),
            'overdue' => (int) $reminders->overdue,
            'dueDay' => (int) $reminders->day,
            'dueWeek' => (int) $reminders->week,
        ];
    }

    /** Filtered, ordered contacts with the number of leads each is linked to. */
    public function contactQuery(string $org, array $filters, ?array $ids = null): Builder
    {
        $query = DB::table('brand_contacts AS c')->where('c.org_id', $org)->select(['c.id', 'c.name', 'c.email', 'c.company', 'c.notes', 'c.version'])
            ->selectSub(DB::table('pipeline_contacts AS pc')->whereColumn('pc.org_id', 'c.org_id')->whereColumn('pc.contact_id', 'c.id')->selectRaw('count(*)'), 'lead_count');
        if ($ids !== null) {
            $query->whereIn('c.id', $ids);
        }
        if (($filters['q'] ?? '') !== '') {
            $query->whereRaw("strpos(lower(c.name || ' ' || c.company || ' ' || coalesce(c.email,'')),lower(?))>0", [$filters['q']]);
        }
        $links = fn ($q) => $q->selectRaw('1')->from('pipeline_contacts AS pl')->whereColumn('pl.org_id', 'c.org_id')->whereColumn('pl.contact_id', 'c.id');
        match ($filters['linked'] ?? '') {
            'linked' => $query->whereExists($links),
            'unlinked' => $query->whereNotExists($links),
            default => null,
        };
        match ($filters['sort'] ?? '') {
            'name_asc' => $query->orderByRaw('lower(c.name) ASC'),
            'name_desc' => $query->orderByRaw('lower(c.name) DESC'),
            'company_asc' => $query->orderByRaw("c.company = '' ASC, lower(c.company) ASC, lower(c.name) ASC"),
            default => $query->orderByDesc('c.created_at'),
        };

        return $query->orderByDesc('c.id');
    }

    /** Pending reminders soonest first; completed ones most recently completed first. */
    public function reminderQuery(string $org, array $filters): Builder
    {
        $query = DB::table('reminders AS r')->where('r.org_id', $org)
            ->leftJoin('pipeline_entries AS pe', fn ($join) => $join->on('pe.org_id', '=', 'r.org_id')->on('pe.id', '=', 'r.pipeline_entry_id'))
            ->select(['r.id', 'r.pipeline_entry_id', 'r.title', 'r.due_at', 'r.completed_at', 'r.version', 'pe.brand_name AS lead_name']);
        $state = $filters['state'] ?? 'pending';
        if ($state === 'completed') {
            return $query->whereNotNull('r.completed_at')->orderByDesc('r.completed_at')->orderBy('r.id');
        }
        if ($state !== 'all') {
            $query->whereNull('r.completed_at');
        }
        if ($state === 'due') {
            $query->where('r.due_at', '<=', now());
        }

        return $query->orderByRaw('r.completed_at IS NOT NULL')->orderByRaw('CASE WHEN r.completed_at IS NULL THEN r.due_at END ASC')
            ->orderByDesc('r.completed_at')->orderBy('r.id');
    }

    /**
     * Deletes or links several contacts at once. Every listed version must match and nothing is
     * changed unless every contact can be changed.
     *
     * @param  array<int, array{id: string, version: int}>  $items
     */
    public function bulk(int $actor, string $org, string $action, array $items, ?string $entry = null, ?int $entryVersion = null): int
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        $versions = array_column($items, 'version', 'id');
        $rows = DB::table('brand_contacts')->where('org_id', $org)->whereIn('id', array_keys($versions))->lockForUpdate()->get(['id', 'version']);
        abort_unless($rows->count() === count($versions), 404);
        foreach ($rows as $row) {
            $this->version($row->version, (int) $versions[$row->id]);
        }
        if ($action === 'link') {
            $lead = DB::table('pipeline_entries')->where('org_id', $org)->where('id', $entry);
            $row = (clone $lead)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->version($row->version, (int) $entryVersion);
            foreach (array_keys($versions) as $id) {
                DB::table('pipeline_contacts')->insertOrIgnore(['org_id' => $org, 'pipeline_entry_id' => $entry, 'contact_id' => $id]);
                $this->audit->record($actor, $org, 'contact.linked', $entry);
            }
            $lead->update(['version' => $entryVersion + 1, 'updated_at' => now()]);

            return count($versions);
        }
        $linked = DB::table('pipeline_contacts')->where('org_id', $org)->whereIn('contact_id', array_keys($versions))->distinct()->count('contact_id');
        if ($linked) {
            throw ValidationException::withMessages(['contact' => $linked === 1
                ? '1 selected contact is linked to a lead. Unlink it before deleting.'
                : $linked.' selected contacts are linked to leads. Unlink them before deleting.'])->status(409);
        }
        DB::table('brand_contacts')->where('org_id', $org)->whereIn('id', array_keys($versions))->delete();
        foreach (array_keys($versions) as $id) {
            $this->audit->record($actor, $org, 'contacts.deleted', $id);
        }

        return count($versions);
    }

    public function save(int $actor, string $org, string $kind, array $data, ?string $id = null, ?int $version = null): string
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        if ($kind === 'reminders' && ! empty($data['pipeline_entry_id'])) {
            abort_unless(DB::table('pipeline_entries')->where('org_id', $org)->where('id', $data['pipeline_entry_id'])->exists(), 404);
        }
        $query = DB::table(self::TABLES[$kind])->where('org_id', $org);
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table(self::TABLES[$kind])->insert([...$data, 'id' => $id, 'org_id' => $org]);
        } else {
            $row = (clone $query)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->version($row->version, $version);
            $query->where('id', $id)->update([...$data, 'version' => $version + 1, 'updated_at' => now()]);
        }
        $this->audit->record($actor, $org, $kind.'.saved', $id);

        return $id;
    }

    public function command(int $actor, string $org, string $id, int $version, string $action, ?string $due): void
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        $query = DB::table('reminders')->where('org_id', $org)->where('id', $id);
        $row = (clone $query)->lockForUpdate()->first();
        abort_unless($row, 404);
        $this->version($row->version, $version);
        $changes = match ($action) {
            'complete' => ['completed_at' => now()], 'reopen' => ['completed_at' => null],
            'snooze' => ['due_at' => $due, 'completed_at' => null],
        };
        $query->update([...$changes, 'version' => $version + 1, 'updated_at' => now()]);
        $this->audit->record($actor, $org, 'reminder.'.$action, $id);
    }

    public function delete(int $actor, string $org, string $kind, string $id, int $version): void
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        $query = DB::table(self::TABLES[$kind])->where('org_id', $org)->where('id', $id);
        $row = (clone $query)->lockForUpdate()->first();
        abort_unless($row, 404);
        $this->version($row->version, $version);
        if ($kind === 'contacts' && DB::table('pipeline_contacts')->where('org_id', $org)->where('contact_id', $id)->exists()) {
            throw ValidationException::withMessages(['contact' => 'Unlink this contact from its leads before deleting.'])->status(409);
        }
        $query->delete();
        $this->audit->record($actor, $org, $kind.'.deleted', $id);
    }

    public function link(int $actor, string $org, string $entry, string $contact, int $version, bool $attach): void
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        $query = DB::table('pipeline_entries')->where('org_id', $org)->where('id', $entry);
        $row = (clone $query)->lockForUpdate()->first();
        abort_unless($row, 404);
        $this->version($row->version, $version);
        abort_unless(DB::table('brand_contacts')->where('org_id', $org)->where('id', $contact)->lockForUpdate()->first(), 404);
        $link = ['org_id' => $org, 'pipeline_entry_id' => $entry, 'contact_id' => $contact];
        if ($attach) {
            DB::table('pipeline_contacts')->insertOrIgnore($link);
        } else {
            DB::table('pipeline_contacts')->where($link)->delete();
        }
        $query->update(['version' => $version + 1, 'updated_at' => now()]);
        $this->audit->record($actor, $org, $attach ? 'contact.linked' : 'contact.unlinked', $entry);
    }

    private function version(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['version' => 'This record changed. Reload before saving again.'])->status(409);
        }
    }
}
