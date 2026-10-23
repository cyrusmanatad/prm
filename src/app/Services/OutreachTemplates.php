<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Requires the locked, transaction-local workspace context. */
class OutreachTemplates
{
    public function __construct(private Pipeline $pipeline, private Audit $audit) {}

    public function listing(string $orgId): array
    {
        return DB::table('outreach_templates')->where('org_id', $orgId)->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'subject', 'body', 'version'])->map(fn ($row) => (array) $row)->all();
    }

    public function save(int $actorId, string $orgId, array $data, ?string $id = null, ?int $version = null): string
    {
        abort_unless($this->pipeline->canWrite($actorId, $orgId), 403);
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('outreach_templates')->insert([...$data, 'id' => $id, 'org_id' => $orgId]);
            $event = 'created';
        } else {
            $this->locked($orgId, $id, $version);
            DB::table('outreach_templates')->where('org_id', $orgId)->where('id', $id)
                ->update([...$data, 'version' => $version + 1, 'updated_at' => now()]);
            $event = 'updated';
        }
        $this->audit->record($actorId, $orgId, 'outreach_template.'.$event, $id);

        return $id;
    }

    public function delete(int $actorId, string $orgId, string $id, int $version): void
    {
        abort_unless($this->pipeline->canWrite($actorId, $orgId), 403);
        $this->locked($orgId, $id, $version);
        DB::table('outreach_templates')->where('org_id', $orgId)->where('id', $id)->delete();
        $this->audit->record($actorId, $orgId, 'outreach_template.deleted', $id);
    }

    private function locked(string $orgId, string $id, int $version): void
    {
        $row = DB::table('outreach_templates')->where('org_id', $orgId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);
        if ($row->version !== $version) {
            throw ValidationException::withMessages(['version' => 'This template changed in another session. Reload before saving again.'])->status(409);
        }
    }
}
