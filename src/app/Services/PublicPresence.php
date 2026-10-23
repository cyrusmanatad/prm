<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicPresence
{
    public function __construct(private Pipeline $pipeline, private Audit $audit) {}

    public function saveKit(int $actor, string $org, array $data, int $version): void
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        // Lock the workspace to serialize first publication as well as edits.
        DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
        $row = DB::table('media_kits')->where('org_id', $org)->lockForUpdate()->first();
        if (($row?->version ?? 0) !== $version) {
            throw ValidationException::withMessages(['version' => 'This kit changed. Reload before saving again.'])->status(409);
        }
        $id = $row?->id ?? (string) Str::uuid();
        $values = [...$data, 'consented_at' => $data['published'] ? now() : null, 'version' => $version + 1];
        if ($row) {
            DB::table('media_kits')->where('id', $id)->update($values);
        } else {
            DB::table('media_kits')->insert([...$values, 'id' => $id, 'org_id' => $org]);
        }
        $this->audit->record($actor, $org, 'media_kit.saved', $id);
    }

    public function leadLink(int $actor, string $org): array
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        DB::table('lead_links')->insert(['id' => $id, 'org_id' => $org, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30)]);
        $this->audit->record($actor, $org, 'lead_link.created', $id);

        return ['id' => $id, 'url' => url('/lead').'#token='.$token];
    }

    public function revoke(int $actor, string $org, string $id): void
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        abort_unless(DB::table('lead_links')->where('org_id', $org)->where('id', $id)->update(['revoked_at' => now()]), 404);
        $this->audit->record($actor, $org, 'lead_link.revoked', $id);
    }

    public function capture(string $token, string $key, array $data): void
    {
        try {
            $result = DB::selectOne('SELECT app_capture_lead(?,?::uuid,?,?,?,?,?::uuid) AS ok', [hash('sha256', $token), $key, hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                $data['brand_name'], $data['contact_name'], $data['email'], (string) Str::uuid()]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '22000') {
                abort(409);
            }throw $e;
        }
        abort_unless($result?->ok, 404);
    }
}
