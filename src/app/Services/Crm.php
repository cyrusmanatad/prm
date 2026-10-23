<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Methods run inside WorkspaceContext, including membership locks. */
class Crm
{
    public const TABLES = ['contacts' => 'brand_contacts', 'reminders' => 'reminders'];

    public function __construct(private Pipeline $pipeline, private Audit $audit) {}

    public function listing(string $org, string $kind, array $filters): array
    {
        $query = DB::table(self::TABLES[$kind])->where('org_id', $org);
        if ($kind === 'contacts' && isset($filters['q']) && $filters['q'] !== '') {
            $query->whereRaw("strpos(lower(name || ' ' || company || ' ' || coalesce(email,'')),lower(?))>0", [$filters['q']]);
        }
        if ($kind === 'reminders') {
            if (($filters['state'] ?? '') === 'completed') {
                $query->whereNotNull('completed_at');
            } elseif (($filters['state'] ?? '') !== 'all') {
                $query->whereNull('completed_at');
            }
            if (($filters['state'] ?? '') === 'due') {
                $query->where('due_at', '<=', now());
            }
        }
        $cursor = $filters[$kind.'_cursor'] ?? null;
        if ($cursor) {
            $row = (clone $query)->where('id', $cursor)->first(['id', 'created_at']);
            abort_unless($row, 404);
            $query->whereRaw('(created_at,id)<(?::timestamptz,?::uuid)', [$row->created_at, $row->id]);
        }
        $columns = $kind === 'contacts' ? ['id', 'name', 'email', 'company', 'notes', 'version'] : ['id', 'pipeline_entry_id', 'title', 'due_at', 'completed_at', 'version'];
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit(26)->get($columns);
        $items = $rows->take(25)->map(function ($row) use ($kind) {
            $item = (array) $row;
            if ($kind === 'reminders') {
                $item['due_at'] = Carbon::parse($row->due_at)->toIso8601String();
                $item['completed_at'] = $row->completed_at ? Carbon::parse($row->completed_at)->toIso8601String() : null;
            }

            return $item;
        })->all();

        return ['items' => $items, 'nextCursor' => $rows->count() > 25 ? end($items)['id'] : null];
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
