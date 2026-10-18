<?php

namespace App\Services;

use App\Enums\PipelineStage;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** All methods require the route's locked, transaction-local workspace context. */
class Pipeline
{
    public function __construct(private Audit $audit) {}

    public function canWrite(int $actorId, string $orgId): bool
    {
        $role = DB::table('memberships')->where('org_id', $orgId)->where('user_id', $actorId)
            ->whereNull('revoked_at')->value('role');

        return $role !== null && WorkspaceRole::from($role) !== WorkspaceRole::Viewer;
    }

    private function entries(string $orgId): Builder
    {
        return DB::table('pipeline_entries')->where('org_id', $orgId);
    }

    public function listing(string $orgId, array $filters): array
    {
        $query = $this->entries($orgId);
        if (! empty($filters['q'])) {
            // Literal substring search; user '%'/'_' are not SQL wildcards.
            $query->whereRaw('strpos(lower(brand_name), lower(?)) > 0', [$filters['q']]);
        }
        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }
        if (! empty($filters['due'])) {
            $query->whereNotNull('next_action_due')->where('next_action_due', '<=', now());
        }
        $counts = (clone $query)->selectRaw('stage, count(*)::int AS count')->groupBy('stage')->get()->all();
        $totals = (clone $query)->selectRaw('currency, sum(value_minor)::text AS amount_minor, count(*)::int AS count')
            ->where('stage', '!=', PipelineStage::Lost->value)->groupBy('currency')->orderBy('currency')->get()->all();
        if (! empty($filters['cursor'])) {
            // ID lookup is scoped before its ordering values are used.
            $cursor = (clone $query)->where('id', $filters['cursor'])->first(['id', 'created_at']);
            abort_unless($cursor, 404);
            $query->whereRaw('(created_at, id) < (?::timestamptz, ?::uuid)', [$cursor->created_at, $cursor->id]);
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit(26)->get();
        $more = $rows->count() > 25;
        $entries = $rows->take(25)->map(fn ($row) => $this->project($row))->values()->all();

        return ['entries' => $entries, 'nextCursor' => $more ? end($entries)['id'] : null,
            'counts' => $counts, 'totals' => $totals];
    }

    public function detail(string $orgId, string $id): array
    {
        $entry = $this->entries($orgId)->where('id', $id)->first();
        abort_unless($entry, 404);
        $activities = DB::table('pipeline_activities')->where('org_id', $orgId)->where('pipeline_entry_id', $id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get(['id', 'kind', 'body', 'occurred_at'])
            ->map(fn ($row) => [...(array) $row, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String()])->all();

        return [...$this->project($entry), 'notes' => $entry->notes, 'activities' => $activities];
    }

    private function project(object $row): array
    {
        return ['id' => $row->id, 'brand_name' => $row->brand_name, 'contact_name' => $row->contact_name,
            'contact_email' => $row->contact_email, 'stage' => $row->stage, 'value_minor' => (string) $row->value_minor,
            'currency' => $row->currency, 'version' => $row->version,
            'next_action_due' => $row->next_action_due ? Carbon::parse($row->next_action_due)->toIso8601String() : null];
    }

    public function create(int $actorId, string $orgId, array $data): string
    {
        abort_unless($this->canWrite($actorId, $orgId), 403);
        $id = (string) Str::uuid();
        DB::table('pipeline_entries')->insert([
            ...$data, 'id' => $id, 'org_id' => $orgId, 'stage' => PipelineStage::Lead->value,
        ]);
        $this->activity($actorId, $orgId, $id, 'created', 'Lead captured.');

        return $id;
    }

    public function change(int $actorId, string $orgId, string $id, int $version, string $kind, array $changes): void
    {
        abort_unless($this->canWrite($actorId, $orgId), 403);
        $entry = $this->entries($orgId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($entry, 404);
        $this->checkVersion($entry->version, $version);
        $body = match ($kind) {
            'stage' => 'Stage changed from '.$entry->stage.' to '.$changes['stage'].'.',
            'snoozed' => 'Next action moved to '.Carbon::parse($changes['next_action_due'])->toIso8601String().'.',
            default => 'Lead details updated.',
        };
        $this->entries($orgId)->where('id', $id)->update([...$changes, 'version' => $version + 1, 'updated_at' => now()]);
        $this->activity($actorId, $orgId, $id, $kind, $body);
    }

    public function addActivity(int $actorId, string $orgId, string $id, int $version, string $kind, string $body): void
    {
        abort_unless($this->canWrite($actorId, $orgId), 403);
        $entry = $this->entries($orgId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($entry, 404);
        $this->checkVersion($entry->version, $version);
        $this->entries($orgId)->where('id', $id)->update(['version' => $version + 1, 'updated_at' => now()]);
        $this->activity($actorId, $orgId, $id, $kind, $body);
    }

    private function checkVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['version' => 'This lead changed in another session. Reload the latest lead before saving again.'])->status(409);
        }
    }

    public function delete(int $actorId, string $orgId, string $id, int $version): void
    {
        abort_unless($this->canWrite($actorId, $orgId), 403);
        $entry = $this->entries($orgId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($entry, 404);
        $this->checkVersion($entry->version, $version);
        $this->entries($orgId)->where('id', $id)->delete();
        $this->audit->record($actorId, $orgId, 'pipeline.deleted', $id);
    }

    private function activity(int $actorId, string $orgId, string $id, string $kind, string $body): void
    {
        DB::table('pipeline_activities')->insert([
            'id' => (string) Str::uuid(), 'org_id' => $orgId, 'actor_id' => $actorId,
            'pipeline_entry_id' => $id, 'kind' => $kind, 'body' => $body,
        ]);
        $this->audit->record($actorId, $orgId, 'pipeline.'.$kind, $id);
    }
}
