<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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

    public const LINK_STATUSES = ['active', 'expired', 'revoked', 'all'];

    public const LINKS_PER_PAGE = 10;

    public function leadLink(int $actor, string $org, string $label = ''): array
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        DB::table('lead_links')->insert(['id' => $id, 'org_id' => $org, 'label' => $label, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30)]);
        $this->audit->record($actor, $org, 'lead_link.created', $id);

        return ['id' => $id, 'url' => url('/lead').'#token='.$token];
    }

    /** A page of lead links with each link's received leads, plus counts for every status. */
    public function links(string $org, string $status, int $page): array
    {
        $now = now();
        $base = DB::table('lead_links AS l')->where('l.org_id', $org);
        $filter = fn ($query, string $state) => match ($state) {
            'active' => $query->whereNull('l.revoked_at')->where('l.expires_at', '>', $now),
            'expired' => $query->whereNull('l.revoked_at')->where('l.expires_at', '<=', $now),
            'revoked' => $query->whereNotNull('l.revoked_at'),
            default => $query,
        };
        $counts = (clone $base)->selectRaw('count(*) FILTER (WHERE revoked_at IS NULL AND expires_at > ?) AS active, count(*) FILTER (WHERE revoked_at IS NULL AND expires_at <= ?) AS expired, count(*) FILTER (WHERE revoked_at IS NOT NULL) AS revoked, count(*) AS total', [$now, $now])->first();
        $counts = ['active' => (int) $counts->active, 'expired' => (int) $counts->expired, 'revoked' => (int) $counts->revoked, 'all' => (int) $counts->total];
        $total = $counts[$status];
        $page = min(max(1, $page), max(1, (int) ceil($total / self::LINKS_PER_PAGE)));
        $items = $filter(clone $base, $status)
            ->select(['l.id', 'l.label', 'l.created_at', 'l.expires_at', 'l.revoked_at'])
            ->selectSub(DB::table('public_lead_receipts AS r')->whereColumn('r.org_id', 'l.org_id')->whereColumn('r.link_id', 'l.id')->selectRaw('count(*)'), 'leads')
            ->orderByDesc('l.created_at')->orderByDesc('l.id')
            ->offset(($page - 1) * self::LINKS_PER_PAGE)->limit(self::LINKS_PER_PAGE)->get()
            ->map(fn ($row) => [
                'id' => $row->id, 'label' => $row->label, 'leads' => (int) $row->leads,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'expires_at' => Carbon::parse($row->expires_at)->toIso8601String(),
                'revoked_at' => $row->revoked_at ? Carbon::parse($row->revoked_at)->toIso8601String() : null,
                'status' => $row->revoked_at ? 'revoked' : (Carbon::parse($row->expires_at)->lte($now) ? 'expired' : 'active'),
            ])->all();

        return ['items' => $items, 'page' => $page, 'perPage' => self::LINKS_PER_PAGE, 'total' => $total, 'counts' => $counts];
    }

    /** Leads that arrived through any of the workspace's lead links. */
    public function receivedLeads(string $org): int
    {
        return DB::table('public_lead_receipts')->where('org_id', $org)->count();
    }

    public function revoke(int $actor, string $org, string $id): void
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        abort_unless(DB::table('lead_links')->where('org_id', $org)->where('id', $id)->update(['revoked_at' => now()]), 404);
        $this->audit->record($actor, $org, 'lead_link.revoked', $id);
    }

    public function capture(string $token, string $key, array $data): void
    {
        // A suspended workspace's lead link answers exactly like a link that does not exist.
        abort_if(DB::selectOne("SELECT app_public_link_suspended('lead',?) AS suspended", [hash('sha256', $token)])->suspended, 404);
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
